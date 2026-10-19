import { execFile } from 'node:child_process';

/**
 * ffprobe summary of a file as `{streams: [...], format: {...}}`.
 */
export const probeFile = (file, { ffprobe = 'ffprobe', timeoutMs = 20000 } = {}) =>
    new Promise((resolve, reject) => {
        execFile(
            ffprobe,
            ['-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', file],
            { timeout: timeoutMs, maxBuffer: 1024 * 1024 },
            (error, stdout) => {
                if (error) {
                    reject(error);
                    return;
                }
                try {
                    resolve(JSON.parse(stdout));
                } catch (parseError) {
                    reject(parseError);
                }
            },
        );
    });

/**
 * Returns the reason a rendered file is not a valid story video, or null when
 * it is: exactly one h264/yuv420p 1080x1920 30 fps video stream, no audio,
 * duration within half a second of the request.
 */
export const storyVideoProblem = (info, expectedSeconds) => {
    const streams = info?.streams ?? [];
    const video = streams.filter((stream) => stream.codec_type === 'video');
    const audio = streams.filter((stream) => stream.codec_type === 'audio');

    if (video.length !== 1) {
        return 'video_stream_count';
    }
    const [v] = video;
    if (v.codec_name !== 'h264') {
        return 'codec';
    }
    if (v.pix_fmt !== 'yuv420p') {
        return 'pixel_format';
    }
    if (v.width !== 1080 || v.height !== 1920) {
        return 'dimensions';
    }
    if (v.r_frame_rate !== '30/1') {
        return 'frame_rate';
    }
    if (audio.length !== 0) {
        return 'unexpected_audio';
    }
    const duration = Number(info?.format?.duration);
    if (!Number.isFinite(duration) || Math.abs(duration - expectedSeconds) > 0.5) {
        return 'duration';
    }

    return null;
};
