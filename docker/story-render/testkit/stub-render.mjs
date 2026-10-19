// Stand-in for the HyperFrames CLI in the service tests.
// argv: mode outputPath pidFile
import { spawn, spawnSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';

const [mode, outputPath, pidFile] = process.argv.slice(2);

const makeVideo = (extra = []) => {
    const result = spawnSync('ffmpeg', ['-y', '-f', 'lavfi', '-i', 'color=c=black:s=1080x1920:r=30:d=3', ...extra, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'ultrafast', '-movflags', '+faststart', outputPath], { stdio: 'ignore' });
    process.exit(result.status ?? 1);
};

// A grandchild in its own session, like chromium started by puppeteer. It carries the nonce from argv for the leftover sweep.
const startGrandchild = (nonce) => {
    const grandchild = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)', nonce], { detached: true, stdio: 'ignore' });
    grandchild.unref();
    writeFileSync(pidFile, `${process.pid} ${grandchild.pid}`);
};

// A valid story video of roughly 10 to 20 MB, larger than any socket buffer, so a download can stall.
const makeBigVideo = () => {
    const result = spawnSync('ffmpeg', ['-y', '-f', 'lavfi', '-i', 'color=c=gray:s=1080x1920:r=30:d=3,noise=alls=60:allf=t+u', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'ultrafast', '-crf', '35', '-movflags', '+faststart', outputPath], { stdio: 'ignore' });
    process.exit(result.status ?? 1);
};

switch (mode) {
    case 'big':
        makeBigVideo();
        break;
    case 'ok':
        makeVideo();
        break;
    case 'with-audio':
        makeVideo(['-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100', '-c:a', 'aac', '-shortest']);
        break;
    case 'slow':
        setTimeout(() => makeVideo(), Number(process.argv[5] ?? 1500));
        break;
    case 'hang':
        startGrandchild('story-test-grandchild');
        setInterval(() => {}, 1000);
        break;
    case 'orphan':
        startGrandchild(process.argv[5] ?? 'story-test-orphan');
        writeFileSync(outputPath, 'not a video');
        setTimeout(() => process.exit(0), 100);
        break;
    case 'garbage':
        writeFileSync(outputPath, 'not a video');
        process.exit(0);
        break;
    case 'fail':
        process.stderr.write(process.argv[5] ?? 'boom');
        process.exit(3);
        break;
    default:
        process.exit(64);
}
