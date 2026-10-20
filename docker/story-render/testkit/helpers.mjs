import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const STUB = path.join(here, 'stub-render.mjs');
export const TOKEN = 'test-token-0123456789abcdef';

export const hasFfmpeg = (() => {
    try {
        execFileSync('ffmpeg', ['-version'], { stdio: 'ignore' });
        execFileSync('ffprobe', ['-version'], { stdio: 'ignore' });

        return true;
    } catch {
        return false;
    }
})();

const createdDirs = new Set();

// Every directory made here is removed when the test process ends (a killed run still leaves its directories behind).
process.on('exit', () => {
    for (const dir of createdDirs) {
        rmSync(dir, { recursive: true, force: true });
    }
});

export const tmpDir = (prefix = 'story-test-') => {
    const dir = mkdtempSync(path.join(os.tmpdir(), prefix));
    createdDirs.add(dir);

    return dir;
};

/** Minimal JPEG header with a start-of-frame marker; enough for header inspection, not for decoding. */
export const fakeJpeg = (width, height, extraBytes = 0) =>
    Buffer.concat([
        Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00]),
        Buffer.from([0xff, 0xc0, 0x00, 0x11, 0x08, height >> 8, height & 0xff, width >> 8, width & 0xff, 0x03, 0x01, 0x22, 0x00, 0x02, 0x11, 0x01, 0x03, 0x11, 0x01]),
        Buffer.alloc(extraBytes, 0x11),
        Buffer.from([0xff, 0xd9]),
    ]);

export const fakePng = (width, height) => {
    const buf = Buffer.alloc(33);
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]).copy(buf, 0);
    buf.writeUInt32BE(13, 8);
    buf.write('IHDR', 12, 'latin1');
    buf.writeUInt32BE(width, 16);
    buf.writeUInt32BE(height, 20);

    return buf;
};

/** A spec every field of which is valid; tests override single fields. */
export const validSpec = (overrides = {}) => ({ duration: 3, effect: 'drift', font: 'inter', accent: '#FDB31B', logoLayer: false, aiNotice: null, ...overrides });

/**
 * Builds a multipart/form-data body the way a real client does: random boundary, binary parts as files
 * (with a filename), the spec as a plain text field.
 */
export const multipartBody = ({ photo = fakeJpeg(1080, 1920), logo = null, spec = validSpec(), boundary = 'story-boundary-8f3a91c2d7' } = {}) => {
    const pieces = [];
    const file = (name, buffer) => {
        pieces.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"; filename="${name}.bin"\r\nContent-Type: application/octet-stream\r\n\r\n`), buffer, Buffer.from('\r\n'));
    };
    const text = (name, value) => {
        pieces.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n`), Buffer.from(value, 'utf8'), Buffer.from('\r\n'));
    };
    if (photo !== null) {
        file('photo', photo);
    }
    if (logo !== null) {
        file('logo', logo);
    }
    if (spec !== null) {
        text('spec', typeof spec === 'string' ? spec : JSON.stringify(spec));
    }
    pieces.push(Buffer.from(`--${boundary}--\r\n`));

    return { body: Buffer.concat(pieces), contentType: `multipart/form-data; boundary=${boundary}` };
};

/** Bait headlines: Greek, Cyrillic, umlauts, the longest allowed string without spaces. */
export const BAIT_SCRIPTS = ['Ελληνικά Παιχνίδια', 'ПРОБУЖДАЕТСЯ', 'Größe ÄÖÜß', 'W'.repeat(80)];

/** Markup and script breakers that must only ever come out as text. */
export const BAIT_INJECTIONS = ['</script><img src=x onerror=alert(1)>', '"};alert(1);//', '<!--', 'a\u{2028}b\u{2029}c', '</div><b>x</b> & <script>'];

export const pidIsAlive = (pid) => {
    try {
        process.kill(pid, 0);

        return true;
    } catch {
        return false;
    }
};

export const waitFor = async (predicate, timeoutMs = 5000) => {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        if (await predicate()) {
            return true;
        }
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    return predicate();
};
