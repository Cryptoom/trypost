import { createHash, timingSafeEqual } from 'node:crypto';
import { createReadStream } from 'node:fs';
import { mkdtemp, rm, stat } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { pipeline } from 'node:stream/promises';
import { fileURLToPath } from 'node:url';
import { writeComposition } from './composition.mjs';
import { loadFontCoverages } from './fonts.mjs';
import { parseMultipart } from './multipart.mjs';
import { probeFile, storyVideoProblem } from './probe.mjs';
import { killLeftovers, runKillable } from './proc.mjs';
import { buildJob, parseSpec } from './spec.mjs';
import { inspectImage, LIMITS, ValidationError } from './validate.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const HYPERFRAMES_CLI = path.join(here, '..', 'node_modules', 'hyperframes', 'bin', 'hyperframes.mjs');

export const MAX_RENDER_TIMEOUT_SECONDS = 240;
/**
 * Render timeout from an environment value: empty, non-numeric or non-positive falls back to the
 * 240 second default, anything else is held between 10 and 240 seconds.
 */
export const renderTimeoutFromEnv = (raw) => {
    const value = Number(raw);
    if (raw === undefined || raw === null || String(raw).trim() === '' || !Number.isFinite(value) || value <= 0) {
        return MAX_RENDER_TIMEOUT_SECONDS;
    }

    return Math.min(Math.max(value, 10), MAX_RENDER_TIMEOUT_SECONDS);
};

export const DEFAULT_RENDER_WORKERS = 1;
/**
 * Render worker count from an environment value: only the plain digits 1 to 4 are accepted, anything
 * else (empty, 0, 5, 2.5, 02, 1e0, text) falls back to the one-worker default.
 */
export const renderWorkersFromEnv = (raw) => {
    if (typeof raw !== 'string' && typeof raw !== 'number') {
        return DEFAULT_RENDER_WORKERS;
    }
    const text = String(raw).trim();

    return /^[1-4]$/.test(text) ? Number(text) : DEFAULT_RENDER_WORKERS;
};

export const TOKEN_HEADER = 'x-story-render-token';

const digest = (value) => createHash('sha256').update(value).digest();

/**
 * Default render command: the pinned HyperFrames CLI, STORY_RENDER_WORKERS workers (default 1), no network use.
 */
export const hyperframesCommand = ({ jobDir, outputPath, env }) => ({
    command: process.execPath,
    args: [HYPERFRAMES_CLI, 'render', jobDir, '--output', outputPath, '--fps', '30', '--workers', String(renderWorkersFromEnv(env.STORY_RENDER_WORKERS)), '--quiet'],
    env: {
        PATH: env.PATH ?? '/usr/local/bin:/usr/bin:/bin',
        HOME: path.join(jobDir, '.home'),
        TMPDIR: jobDir,
        DO_NOT_TRACK: '1',
        HYPERFRAMES_NO_TELEMETRY: '1',
        HYPERFRAMES_NO_UPDATE_CHECK: '1',
        HYPERFRAMES_BROWSER_PATH: env.HYPERFRAMES_BROWSER_PATH ?? '/usr/bin/chromium',
        HYPERFRAMES_EXTRACT_CACHE_DIR: path.join(jobDir, '.cache'),
        HYPERFRAMES_FONT_CACHE_DIR: path.join(jobDir, '.fonts'),
        PRODUCER_RENDERS_DIR: path.join(jobDir, '.renders'),
    },
});

/**
 * Builds the HTTP server. Everything the tests need to vary is injectable:
 * the render command, the working directory, the leftover pattern and kill options.
 */
export const createApp = ({
    token,
    renderTimeoutSeconds = MAX_RENDER_TIMEOUT_SECONDS,
    workDir = os.tmpdir(),
    fontsDir = path.join(here, '..', 'template', 'fonts'),
    buildCommand = hyperframesCommand,
    compose = writeComposition,
    ffprobe = 'ffprobe',
    leftoverPattern = null,
    streamIdleMs = 60000,
    logStderr = false,
    killOptions,
    log = (entry) => process.stdout.write(`${JSON.stringify(entry)}\n`),
    env = process.env,
}) => {
    if (typeof token !== 'string' || token.length < 16) {
        throw new Error('STORY_RENDER_TOKEN must be set (at least 16 characters).');
    }
    const expectedToken = digest(token);
    const timeoutMs = Math.min(renderTimeoutSeconds, MAX_RENDER_TIMEOUT_SECONDS) * 1000;
    const coverages = loadFontCoverages(fontsDir);
    let busy = false;

    const sendJson = (res, status, body, extraHeaders = {}) => {
        if (res.headersSent || res.writableEnded) {
            return;
        }
        const payload = JSON.stringify(body);
        res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload), ...extraHeaders });
        res.end(payload);
    };

    const authorized = (req) => {
        const given = req.headers[TOKEN_HEADER];

        return typeof given === 'string' && timingSafeEqual(digest(given), expectedToken);
    };

    // Reads the request body with a hard byte limit, counted while streaming.
    const readBody = (req) =>
        new Promise((resolve, reject) => {
            const declared = Number(req.headers['content-length']);
            if (Number.isFinite(declared) && declared > LIMITS.maxBodyBytes) {
                reject(new ValidationError('body_too_large', 413));
                return;
            }
            const chunks = [];
            let received = 0;
            req.on('data', (chunk) => {
                received += chunk.length;
                if (received > LIMITS.maxBodyBytes) {
                    // Stop keeping bytes; the connection is closed once the 413 is out.
                    chunks.length = 0;
                    reject(new ValidationError('body_too_large', 413));
                    return;
                }
                chunks.push(chunk);
            });
            req.on('end', () => resolve(Buffer.concat(chunks)));
            req.on('error', reject);
            req.on('close', () => {
                if (!req.complete) {
                    reject(new ValidationError('client_aborted', 499));
                }
            });
        });

    const handleRender = async (req, res, url) => {
        if (busy) {
            log({ event: 'render_rejected', reason: 'busy' });
            sendJson(res, 503, { error: 'busy' }, { 'Retry-After': '30' });
            req.resume();
            return;
        }
        busy = true;
        // Bound to THIS job: the slot is freed once, as soon as the render is over, and the finally below
        // is only a safety net. A second call must never free the slot a newer job has taken in between
        // (the first job's download may still run when the next render starts).
        let released = false;
        const release = () => {
            if (!released) {
                released = true;
                busy = false;
            }
        };
        const job = { dir: null };

        try {
            await renderJob(req, res, url, release, job);
        } finally {
            release();
            if (job.dir !== null) {
                await rm(job.dir, { recursive: true, force: true }).catch(() => {});
                job.dir = null;
            }
        }
    };

    const renderJob = async (req, res, url, release, job) => {
        const abort = new AbortController();
        // `close` fires for every response; only an unfinished one means the client went away mid-render.
        res.on('close', () => {
            if (!res.writableFinished) {
                abort.abort();
            }
        });

        const startedAt = Date.now();
        const seconds = () => Math.round((Date.now() - startedAt) / 1000);
        // What to do once the render slot is free again: send an error, stream a file, or nothing.
        let outcome = { status: 500, body: { error: 'internal_error' } };
        let durationSeconds = 0;
        let hasText = false;

        try {
            if (url.search !== '') {
                throw new ValidationError('unexpected_query');
            }
            const body = await readBody(req);
            const parts = await parseMultipart(body, req.headers['content-type']);
            const parsedSpec = parseSpec(parts.spec);
            const photoInfo = inspectImage(parts.photo);
            let logoInfo = null;
            if (parts.logo !== null) {
                try {
                    logoInfo = inspectImage(parts.logo, { maxSide: LIMITS.maxLogoSide });
                } catch (error) {
                    throw error instanceof ValidationError ? new ValidationError(`logo_${error.code}`, 400, 'logo') : error;
                }
            }
            const built = buildJob(parsedSpec, { logoInfo, coverages });
            if (built.aiNoticeFallback) {
                log({ event: 'ai_notice_fallback', reason: 'not_drawable' });
            }
            durationSeconds = built.duration;
            hasText = built.hasText;

            job.dir = await mkdtemp(path.join(workDir, 'story-job-'));
            await compose(job.dir, {
                photo: { buffer: parts.photo, ext: photoInfo.ext },
                logo: logoInfo === null ? null : { buffer: parts.logo, ext: logoInfo.ext },
                job: built,
            });

            const outputPath = path.join(job.dir, 'out.mp4');
            const spec = buildCommand({ jobDir: job.dir, outputPath, env });
            const result = await runKillable({ ...spec, cwd: job.dir, timeoutMs, signal: abort.signal, killOptions });

            if (result.aborted) {
                log({ event: 'render_aborted', reason: 'client_closed' });
                outcome = null;
            } else if (result.timedOut) {
                log({ event: 'render_failed', reason: 'timeout' });
                outcome = { status: 504, body: { error: 'render_timeout' } };
            } else if (result.exitCode !== 0) {
                // stderr can quote composition text, so only the size of its kept tail (max 4000 characters) is logged unless debugging is switched on.
                log({ event: 'render_failed', reason: 'exit', exitCode: result.exitCode, stderrChars: result.stderrTail.length, ...(logStderr ? { stderr: result.stderrTail.slice(-600) } : {}) });
                outcome = { status: 500, body: { error: 'render_failed' } };
            } else {
                const info = await probeFile(outputPath, { ffprobe }).catch(() => null);
                const problem = info === null ? 'unreadable' : storyVideoProblem(info, durationSeconds);
                if (problem !== null) {
                    log({ event: 'render_failed', reason: 'invalid_output', problem });
                    outcome = { status: 500, body: { error: 'invalid_output' } };
                } else {
                    const { size } = await stat(outputPath);
                    outcome = { file: outputPath, size, hasText };
                }
            }
        } catch (error) {
            if (error instanceof ValidationError && error.status === 499) {
                outcome = null;
            } else if (error instanceof ValidationError) {
                log({ event: 'render_rejected', reason: error.code, ...(error.field === null ? {} : { field: error.field }) });
                outcome = { status: error.status, body: { error: error.code, ...(error.field === null ? {} : { field: error.field }) } };
            } else {
                log({ event: 'render_failed', reason: 'internal', message: String(error?.message ?? error).slice(0, 300) });
            }
        }

        // The render is over: sweep orphans and free the slot. Streaming a finished file does not hold it.
        try {
            const leftovers = leftoverPattern === null ? [] : killLeftovers(leftoverPattern);
            if (leftovers.length > 0) {
                log({ event: 'leftover_killed', count: leftovers.length });
            }
        } catch (error) {
            log({ event: 'sweep_failed', message: String(error?.message ?? error).slice(0, 200) });
        }
        // An error answer goes out only after the job directory is gone; the finally in handleRender is the safety net.
        const removeJobDir = async () => {
            if (job.dir !== null) {
                await rm(job.dir, { recursive: true, force: true }).catch(() => {});
                job.dir = null;
            }
        };

        if (outcome?.file === undefined) {
            await removeJobDir();
            release();
            if (outcome !== null) {
                sendJson(res, outcome.status, outcome.body, req.complete ? {} : { Connection: 'close' });
                // A rejected upload may still be streaming in: cut it off once the answer is out.
                res.once('finish', () => {
                    if (!req.complete) {
                        req.destroy();
                    }
                });
            }
            return;
        }

        release();
        try {
            // Idle protection only for the download of a finished file. During the render nothing
            // flows over the socket for minutes, the render timeout bounds that phase instead.
            res.socket?.setTimeout(streamIdleMs, () => res.destroy());
            res.writeHead(200, { 'Content-Type': 'video/mp4', 'Content-Length': outcome.size, 'X-Render-Seconds': String(seconds()) });
            await pipeline(createReadStream(outcome.file), res);
            log({ event: 'render_ok', seconds: seconds(), bytes: outcome.size, text: outcome.hasText });
        } catch (error) {
            log({ event: 'render_aborted', reason: error?.code === 'ERR_STREAM_PREMATURE_CLOSE' ? 'client_closed_during_download' : 'stream_error' });
        } finally {
            await removeJobDir();
        }
    };

    const server = http.createServer((req, res) => {
        const url = new URL(req.url ?? '/', 'http://localhost');

        if (req.method === 'GET' && url.pathname === '/health') {
            sendJson(res, 200, { status: 'ok', busy });
            return;
        }
        if (url.pathname !== '/render') {
            sendJson(res, 404, { error: 'not_found' });
            req.resume();
            return;
        }
        if (req.method !== 'POST') {
            sendJson(res, 405, { error: 'method_not_allowed' }, { Allow: 'POST' });
            req.resume();
            return;
        }
        if (!authorized(req)) {
            sendJson(res, 401, { error: 'unauthorized' });
            req.resume();
            return;
        }
        handleRender(req, res, url).catch((error) => {
            log({ event: 'render_failed', reason: 'internal', message: String(error?.message ?? error).slice(0, 300) });
            sendJson(res, 500, { error: 'internal_error' });
        });
    });

    server.timeoutMs = timeoutMs;
    server.maxConnections = 32;
    server.requestTimeout = 60000;
    server.headersTimeout = 15000;
    server.keepAliveTimeout = 5000;

    return server;
};
