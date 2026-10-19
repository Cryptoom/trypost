import { createApp, renderTimeoutFromEnv } from './lib/app.mjs';

const port = Number(process.env.PORT ?? 3000);
const server = createApp({
    token: process.env.STORY_RENDER_TOKEN,
    renderTimeoutSeconds: renderTimeoutFromEnv(process.env.STORY_RENDER_TIMEOUT_SECONDS),
    workDir: process.env.STORY_RENDER_WORKDIR || undefined,
    logStderr: process.env.STORY_RENDER_LOG_STDERR === '1',
    // Only this container's single render ever runs chromium or ffmpeg, so anything left after a job is an orphan.
    leftoverPattern: /(?:^|\/)(?:chrom[\w-]*|ffmpeg)(?:\s|$)/i,
});

server.listen(port, '0.0.0.0', () => {
    process.stdout.write(`${JSON.stringify({ event: 'listening', port })}\n`);
});

const shutdown = () => server.close(() => process.exit(0));
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
