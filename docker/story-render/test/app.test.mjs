import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { rm } from 'node:fs/promises';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import test from 'node:test';
import { createApp, renderTimeoutFromEnv } from '../lib/app.mjs';
import { STUB, TOKEN, encodeHeadline, fakeJpeg, hasFfmpeg, pidIsAlive, tmpDir, waitFor } from '../testkit/helpers.mjs';

const stubCommand = (mode, extra = []) => ({ outputPath, jobDir }) => ({
    command: process.execPath,
    args: [STUB, mode, outputPath, path.join(path.dirname(jobDir), `pids-${mode}-${path.basename(jobDir)}`), ...extra],
    env: { PATH: process.env.PATH },
});

const start = async (options = {}) => {
    const workDir = tmpDir('story-work-');
    const logs = [];
    const server = createApp({ token: TOKEN, workDir, log: (entry) => logs.push(entry), buildCommand: stubCommand('ok'), ...options });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;

    return {
        server,
        workDir,
        logs,
        port,
        stop: async () => {
            server.closeAllConnections();
            await new Promise((resolve) => server.close(resolve));
            await rm(workDir, { recursive: true, force: true });
        },
    };
};

const post = ({ port, query = 'duration=3', headers = {}, body = fakeJpeg(1080, 1920), token = TOKEN }) =>
    new Promise((resolve, reject) => {
        const req = http.request({ host: '127.0.0.1', port, method: 'POST', path: `/render?${query}`, headers: { ...(token === null ? {} : { 'X-Story-Render-Token': token }), 'Content-Length': body.length, ...headers } }, (res) => {
            const chunks = [];
            res.on('data', (chunk) => chunks.push(chunk));
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks) }));
        });
        // A server that answers 413 early may close while the body is still being written.
        req.on('error', () => resolve({ status: 0, headers: {}, body: Buffer.alloc(0) }));
        req.end(body);
    });

const json = (response) => JSON.parse(response.body.toString('utf8'));
const jobDirs = (workDir) => readdirSync(workDir).filter((name) => name.startsWith('story-job-'));

test('service: refuses to start without a token', () => {
    assert.throws(() => createApp({ token: undefined }), /STORY_RENDER_TOKEN/);
    assert.throws(() => createApp({ token: 'short' }), /STORY_RENDER_TOKEN/);
});

test('service: render timeout is capped at 240 seconds', async () => {
    const ctx = await start({ renderTimeoutSeconds: 9999 });
    try {
        assert.equal(ctx.server.timeoutMs, 240000);
    } finally {
        await ctx.stop();
    }
});

test('service: /health answers without a token', async () => {
    const ctx = await start();
    try {
        const res = await new Promise((resolve) => http.get({ host: '127.0.0.1', port: ctx.port, path: '/health' }, (r) => { let b = ''; r.on('data', (c) => (b += c)); r.on('end', () => resolve({ status: r.statusCode, body: JSON.parse(b) })); }));
        assert.equal(res.status, 200);
        assert.equal(res.body.status, 'ok');
    } finally {
        await ctx.stop();
    }
});

test('service: a missing or wrong token is a 401 and starts no render', async () => {
    const ctx = await start({ buildCommand: () => { throw new Error('render must not start'); } });
    try {
        // includes a wrong token of exactly the right length
        for (const token of [null, 'wrong', '', `${TOKEN}x`, TOKEN.replace(/.$/, 'X'), 'x'.repeat(TOKEN.length)]) {
            const res = await post({ port: ctx.port, token });
            assert.equal(res.status, 401, `token ${JSON.stringify(token)}`);
        }
        assert.deepEqual(jobDirs(ctx.workDir), []);
    } finally {
        await ctx.stop();
    }
});

test('service: unknown paths and wrong methods are rejected', async () => {
    const ctx = await start();
    try {
        const notFound = await new Promise((resolve) => http.get({ host: '127.0.0.1', port: ctx.port, path: '/nope' }, (r) => { r.resume(); resolve(r.statusCode); }));
        assert.equal(notFound, 404);
        const wrongMethod = await new Promise((resolve) => http.get({ host: '127.0.0.1', port: ctx.port, path: '/render' }, (r) => { r.resume(); resolve(r.statusCode); }));
        assert.equal(wrongMethod, 405);
    } finally {
        await ctx.stop();
    }
});

test('service: input is validated before anything renders', async () => {
    const ctx = await start({ buildCommand: () => { throw new Error('render must not start'); } });
    try {
        assert.equal((await post({ port: ctx.port, body: Buffer.from('<svg/>'), headers: { 'Content-Type': 'image/jpeg' } })).status, 415);
        assert.equal((await post({ port: ctx.port, query: 'duration=2' })).status, 400);
        assert.equal((await post({ port: ctx.port, query: '' })).status, 400);
        assert.equal((await post({ port: ctx.port, headers: { 'X-Story-Headline-B64': encodeHeadline('W'.repeat(81)) } })).status, 400);
        assert.equal((await post({ port: ctx.port, body: fakeJpeg(5000, 100) })).status, 413);
        assert.deepEqual(jobDirs(ctx.workDir), []);
    } finally {
        await ctx.stop();
    }
});

test('service: a declared Content-Length over 8 MB is a 413 before any byte is read', async () => {
    const ctx = await start({ buildCommand: () => { throw new Error('render must not start'); } });
    try {
        const answer = await new Promise((resolve, reject) => {
            const socket = net.connect(ctx.port, '127.0.0.1', () => {
                socket.write(`POST /render?duration=3 HTTP/1.1\r\nHost: x\r\nX-Story-Render-Token: ${TOKEN}\r\nContent-Length: ${9 * 1024 * 1024}\r\n\r\n`);
            });
            let data = '';
            socket.on('data', (chunk) => { data += chunk.toString('latin1'); socket.destroy(); resolve(data); });
            socket.on('error', reject);
        });
        assert.match(answer, /^HTTP\/1\.1 413 /);
        assert.match(answer, /image_too_large/);
    } finally {
        await ctx.stop();
    }
});

test('service: the body limit is enforced while streaming, without a Content-Length', async () => {
    const ctx = await start({ buildCommand: () => { throw new Error('render must not start'); } });
    try {
        const status = await new Promise((resolve) => {
            const req = http.request({ host: '127.0.0.1', port: ctx.port, method: 'POST', path: '/render?duration=3', headers: { 'X-Story-Render-Token': TOKEN, 'Transfer-Encoding': 'chunked' } }, (res) => { res.resume(); resolve(res.statusCode); });
            req.on('error', () => resolve('closed'));
            const chunk = Buffer.alloc(1024 * 1024, 0x11);
            for (let i = 0; i < 10; i += 1) {
                req.write(chunk);
            }
            req.end();
        });
        assert.ok(status === 413 || status === 'closed', `got ${status}`);
    } finally {
        await ctx.stop();
    }
});

test('service: a failing render is a 500 and the job directory is removed', async () => {
    const ctx = await start({ buildCommand: stubCommand('fail') });
    try {
        const res = await post({ port: ctx.port });
        assert.equal(res.status, 500);
        assert.equal(json(res).error, 'render_failed');
        assert.deepEqual(jobDirs(ctx.workDir), []);
    } finally {
        await ctx.stop();
    }
});

test('service: output that is not a story video is rejected', async () => {
    const ctx = await start({ buildCommand: stubCommand('garbage') });
    try {
        const res = await post({ port: ctx.port });
        assert.equal(res.status, 500);
        assert.equal(json(res).error, 'invalid_output');
    } finally {
        await ctx.stop();
    }
});

test('service: a video that carries an audio track is rejected (ffprobe guard)', { skip: hasFfmpeg ? false : 'ffmpeg missing' }, async () => {
    const ctx = await start({ buildCommand: stubCommand('with-audio') });
    try {
        const res = await post({ port: ctx.port });
        assert.equal(res.status, 500);
        assert.equal(json(res).error, 'invalid_output');
        assert.ok(ctx.logs.some((entry) => entry.problem === 'unexpected_audio'));
    } finally {
        await ctx.stop();
    }
});

test('service: success returns the MP4 and leaves nothing behind', { skip: hasFfmpeg ? false : 'ffmpeg missing' }, async () => {
    let seen = null;
    const ctx = await start({ compose: async (jobDir, params) => { seen = params; const { writeComposition } = await import('../lib/composition.mjs'); return writeComposition(jobDir, params); } });
    try {
        const res = await post({ port: ctx.port, headers: { 'X-Story-Headline-B64': encodeHeadline('Sale 🔥 Ελληνικά\u0007') } });
        assert.equal(res.status, 200);
        assert.equal(res.headers['content-type'], 'video/mp4');
        assert.equal(res.body.subarray(4, 8).toString('latin1'), 'ftyp');
        assert.equal(seen.headline, 'Sale Ελληνικά', 'emoji and control characters are stripped before composing');
        assert.equal(seen.durationSeconds, 3);
        assert.equal(seen.ext, 'jpg');
        assert.ok(await waitFor(() => jobDirs(ctx.workDir).length === 0), 'job dir not removed after streaming');
        assert.ok(!JSON.stringify(ctx.logs).includes('Ελληνικά'), 'the headline must not be logged');
    } finally {
        await ctx.stop();
    }
});

test('service: while one render runs, a second request is an immediate 503, then service recovers', { skip: hasFfmpeg ? false : 'ffmpeg missing' }, async () => {
    const ctx = await start({ buildCommand: stubCommand('slow') });
    try {
        const first = post({ port: ctx.port });
        assert.ok(await waitFor(() => jobDirs(ctx.workDir).length === 1));
        const startedAt = Date.now();
        const second = await post({ port: ctx.port });
        assert.equal(second.status, 503);
        assert.ok(Date.now() - startedAt < 1000, 'busy must answer at once');
        assert.equal(second.headers['retry-after'], '30');
        assert.equal((await first).status, 200);
        assert.equal((await post({ port: ctx.port })).status, 200);
    } finally {
        await ctx.stop();
    }
});

test('service: a render that hangs is a 504, its processes (incl. a setsid grandchild) are gone, the dir is removed', async () => {
    const ctx = await start({ buildCommand: stubCommand('hang'), renderTimeoutSeconds: 1 });
    try {
        const res = await post({ port: ctx.port });
        assert.equal(res.status, 504);
        assert.equal(json(res).error, 'render_timeout');
        const pidFile = path.join(ctx.workDir, readdirSync(ctx.workDir).find((name) => name.startsWith('pids-hang')));
        const [childPid, grandchildPid] = readFileSync(pidFile, 'utf8').split(' ').map(Number);
        assert.ok(await waitFor(() => !pidIsAlive(childPid) && !pidIsAlive(grandchildPid)), 'processes survived the timeout');
        assert.deepEqual(jobDirs(ctx.workDir), []);
    } finally {
        await ctx.stop();
    }
});

test('service: when the client disconnects mid-render the render tree is killed and the service is free again', async () => {
    const ctx = await start({ buildCommand: stubCommand('hang'), renderTimeoutSeconds: 60 });
    try {
        const req = http.request({ host: '127.0.0.1', port: ctx.port, method: 'POST', path: '/render?duration=3', headers: { 'X-Story-Render-Token': TOKEN, 'Content-Length': fakeJpeg(1080, 1920).length } });
        req.on('error', () => {});
        req.end(fakeJpeg(1080, 1920));
        const pidFileName = () => readdirSync(ctx.workDir).find((name) => name.startsWith('pids-hang'));
        assert.ok(await waitFor(() => { const f = pidFileName(); return f && readFileSync(path.join(ctx.workDir, f), 'utf8').includes(' '); }), 'render did not start');
        const [childPid, grandchildPid] = readFileSync(path.join(ctx.workDir, pidFileName()), 'utf8').split(' ').map(Number);
        req.destroy();
        assert.ok(await waitFor(() => !pidIsAlive(childPid) && !pidIsAlive(grandchildPid), 8000), 'render tree survived the client abort');
        assert.ok(await waitFor(() => jobDirs(ctx.workDir).length === 0));
        const next = await post({ port: ctx.port, body: Buffer.from('<svg/>') });
        assert.equal(next.status, 415, 'busy flag must have been released');
    } finally {
        await ctx.stop();
    }
});

test('service: an orphan left behind by a finished render is swept up', async () => {
    const nonce = `story-test-sweep-${process.pid}-${Date.now()}`;
    const ctx = await start({ buildCommand: stubCommand('orphan', [nonce]), leftoverPattern: new RegExp(nonce) });
    try {
        const res = await post({ port: ctx.port });
        assert.equal(res.status, 500);
        const pidFile = path.join(ctx.workDir, readdirSync(ctx.workDir).find((name) => name.startsWith('pids-orphan')));
        const [, orphanPid] = readFileSync(pidFile, 'utf8').split(' ').map(Number);
        assert.ok(await waitFor(() => !pidIsAlive(orphanPid)), 'orphan survived');
        assert.ok(ctx.logs.some((entry) => entry.event === 'leftover_killed'));
    } finally {
        await ctx.stop();
    }
});

test('service: a render that outlasts the socket idle limit still completes with 200 (no idle kill during the render)', { skip: hasFfmpeg ? false : 'ffmpeg missing' }, async () => {
    // The old code destroyed the response after the idle limit of silence on the socket, i.e. mid-render.
    const ctx = await start({ buildCommand: stubCommand('slow', ['2500']), streamIdleMs: 800 });
    try {
        const startedAt = Date.now();
        const res = await post({ port: ctx.port });
        assert.ok(Date.now() - startedAt >= 2400, 'render must really take longer than the idle limit');
        assert.equal(res.status, 200);
        assert.equal(res.body.subarray(4, 8).toString('latin1'), 'ftyp');
    } finally {
        await ctx.stop();
    }
});

test('service: a throwing orphan sweep is logged and swallowed, the job directory is still removed', async () => {
    const throwing = { test() { throw new Error('sweep exploded'); } };
    const ctx = await start({ buildCommand: stubCommand('fail'), leftoverPattern: throwing });
    try {
        const res = await post({ port: ctx.port });
        assert.equal(res.status, 500);
        assert.equal(json(res).error, 'render_failed');
        assert.ok(ctx.logs.some((entry) => entry.event === 'sweep_failed'));
        assert.deepEqual(jobDirs(ctx.workDir), []);
        assert.equal((await post({ port: ctx.port, body: Buffer.from('<svg/>') })).status, 415, 'slot is free');
    } finally {
        await ctx.stop();
    }
});

test('service: when the handler itself throws, the slot is freed and the job directory removed (safety net)', async () => {
    // The sweep fails and so does the log call that reports it: the error escapes renderJob.
    const throwing = { test() { throw new Error('sweep exploded'); } };
    const logs = [];
    const log = (entry) => {
        logs.push(entry);
        if (entry.event === 'sweep_failed') {
            throw new Error('log exploded');
        }
    };
    const ctx = await start({ buildCommand: stubCommand('fail'), leftoverPattern: throwing, log });
    try {
        const first = await post({ port: ctx.port });
        assert.equal(first.status, 500);
        assert.equal(json(first).error, 'internal_error');
        assert.ok(logs.some((entry) => entry.event === 'render_failed' && entry.reason === 'internal'), 'the failure is logged');
        assert.ok(await waitFor(() => jobDirs(ctx.workDir).length === 0), 'job dir left behind');
        const second = await post({ port: ctx.port, body: Buffer.from('<svg/>') });
        assert.notEqual(second.status, 503, 'a stuck busy flag would answer 503');
    } finally {
        await ctx.stop();
    }
});

test('service: a failing render logs only the size of its stderr, never the text (it may quote the headline)', async () => {
    const bait = 'Ελληνικά-secret-headline';
    const ctx = await start({ buildCommand: stubCommand('fail', [`error near ${bait}`]) });
    try {
        const res = await post({ port: ctx.port, headers: { 'X-Story-Headline-B64': encodeHeadline(bait) } });
        assert.equal(res.status, 500);
        const entry = ctx.logs.find((log) => log.reason === 'exit');
        assert.equal(entry.exitCode, 3);
        assert.ok(entry.stderrChars > 0);
        assert.ok(!JSON.stringify(ctx.logs).includes('secret-headline'));
    } finally {
        await ctx.stop();
    }

    const debug = await start({ buildCommand: stubCommand('fail', [`error near ${bait}`]), logStderr: true });
    try {
        await post({ port: debug.port, headers: { 'X-Story-Headline-B64': encodeHeadline(bait) } });
        assert.ok(JSON.stringify(debug.logs).includes('secret-headline'), 'debug flag opts in');
    } finally {
        await debug.stop();
    }
});

// Starts a render and returns the paused response, so the finished video stays unread in the socket buffers.
const startStalledDownload = (port) =>
    new Promise((resolve, reject) => {
        const body = fakeJpeg(1080, 1920);
        const req = http.request({ host: '127.0.0.1', port, method: 'POST', path: '/render?duration=3', headers: { 'X-Story-Render-Token': TOKEN, 'Content-Length': body.length } }, (res) => {
            res.pause();
            resolve({ req, res });
        });
        req.on('error', reject);
        req.end(body);
    });

const readAll = (res) =>
    new Promise((resolve, reject) => {
        let bytes = 0;
        res.on('data', (chunk) => { bytes += chunk.length; });
        res.on('end', () => resolve(bytes));
        res.on('error', reject);
        res.on('aborted', () => reject(new Error('aborted')));
        res.resume();
    });

const health = (port) =>
    new Promise((resolve) => http.get({ host: '127.0.0.1', port, path: '/health' }, (r) => { let b = ''; r.on('data', (c) => (b += c)); r.on('end', () => resolve(JSON.parse(b))); }));

test('service: a client that stops reading the finished video is cut off after the idle limit and nothing is left behind', { skip: hasFfmpeg ? false : 'ffmpeg missing' }, async () => {
    const ctx = await start({ buildCommand: stubCommand('big'), streamIdleMs: 700 });
    let res = null;
    try {
        ({ res } = await startStalledDownload(ctx.port));
        // Larger than the socket buffers of any platform (Linux defaults: up to 6 MiB receive plus 4 MiB send).
        assert.ok(Number(res.headers['content-length']) > 24 * 1024 * 1024, 'the video must be larger than the socket buffers');
        assert.ok(await waitFor(() => ctx.logs.some((entry) => entry.reason === 'client_closed_during_download'), 8000), 'stalled download was never cut off');
        assert.ok(await waitFor(() => jobDirs(ctx.workDir).length === 0), 'job dir left behind');
        assert.equal((await health(ctx.port)).busy, false);
    } finally {
        res?.destroy();
        await ctx.stop();
    }
});

test('service: the first job finishing its download never frees the slot of a render that started in between', { skip: hasFfmpeg ? false : 'ffmpeg missing' }, async () => {
    let started = 0;
    const buildCommand = (context) => (++started === 1 ? stubCommand('big')(context) : stubCommand('slow', ['3000'])(context));
    const ctx = await start({ buildCommand, streamIdleMs: 20000 });
    try {
        // A renders fast, frees its slot and then stalls in the download.
        const a = await startStalledDownload(ctx.port);
        assert.equal((await health(ctx.port)).busy, false, 'the slot is free while A only downloads');

        // B starts its render in the freed slot.
        const b = post({ port: ctx.port });
        assert.ok(await waitFor(() => started === 2), 'B did not start');
        assert.equal((await health(ctx.port)).busy, true);

        // A finishes its download: that must not free B's slot.
        await readAll(a.res);
        // The client has the last byte; wait until the server side of A is completely done: its job directory
        // is gone (the finally with the second release runs right after), only B's directory is left.
        assert.ok(await waitFor(() => jobDirs(ctx.workDir).length === 1), 'A did not clean up');
        assert.equal((await health(ctx.port)).busy, true, 'A freed the slot of B');
        const c = await post({ port: ctx.port });
        assert.equal(c.status, 503, 'a third job must be refused while B renders');
        assert.equal((await b).status, 200);
        assert.equal((await health(ctx.port)).busy, false);
    } finally {
        await ctx.stop();
    }
});

test('service: no more than 32 connections are held, further ones are dropped', async () => {
    const ctx = await start();
    const sockets = [];
    try {
        assert.equal(ctx.server.maxConnections, 32);
        for (let i = 0; i < 32; i += 1) {
            const socket = net.connect(ctx.port, '127.0.0.1');
            socket.on('error', () => {});
            sockets.push(socket);
        }
        assert.ok(await waitFor(() => new Promise((resolve) => ctx.server.getConnections((error, count) => resolve(count === 32))), 5000));
        const extra = net.connect(ctx.port, '127.0.0.1');
        extra.on('error', () => {});
        sockets.push(extra);
        const dropped = await new Promise((resolve) => {
            extra.on('close', () => resolve(true));
            setTimeout(() => resolve(false), 3000);
        });
        assert.equal(dropped, true, 'the 33rd connection must be dropped');
    } finally {
        sockets.forEach((socket) => socket.destroy());
        await ctx.stop();
    }
});

test('service: the render timeout from the environment falls back to 240 and is held between 10 and 240', () => {
    for (const raw of [undefined, null, '', '   ', 'abc', 'NaN', '0', '-5']) {
        assert.equal(renderTimeoutFromEnv(raw), 240, `${JSON.stringify(raw)}`);
    }
    assert.equal(renderTimeoutFromEnv('5'), 10);
    assert.equal(renderTimeoutFromEnv('10'), 10);
    assert.equal(renderTimeoutFromEnv('60'), 60);
    assert.equal(renderTimeoutFromEnv('240'), 240);
    assert.equal(renderTimeoutFromEnv('9999'), 240);
    assert.equal(renderTimeoutFromEnv(90), 90);
});
