// Input validation for the render service. Nothing from the caller is ever used as a path or URL.

export const LIMITS = Object.freeze({
    maxImageBytes: 8 * 1024 * 1024,
    maxImageSide: 4096,
    minDuration: 3,
    maxDuration: 20,
    maxHeadlineChars: 80,
});

export class ValidationError extends Error {
    constructor(code, status = 400) {
        super(code);
        this.code = code;
        this.status = status;
    }
}

const JPEG_SOF_MARKERS = new Set([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf]);
const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

const pngSize = (buf) => {
    // 8 byte signature, then the IHDR chunk: length(4) "IHDR"(4) width(4) height(4)
    if (buf.length < 24 || buf.toString('latin1', 12, 16) !== 'IHDR') {
        return null;
    }
    return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
};

const jpegSize = (buf) => {
    let offset = 2;
    while (offset + 4 <= buf.length) {
        if (buf[offset] !== 0xff) {
            return null;
        }
        const marker = buf[offset + 1];
        if (marker === 0xff) {
            offset += 1;
            continue;
        }
        if (marker === 0xd8 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
            offset += 2;
            continue;
        }
        const length = buf.readUInt16BE(offset + 2);
        if (length < 2) {
            return null;
        }
        if (JPEG_SOF_MARKERS.has(marker)) {
            if (offset + 9 > buf.length) {
                return null;
            }
            return { height: buf.readUInt16BE(offset + 5), width: buf.readUInt16BE(offset + 7) };
        }
        offset += 2 + length;
    }
    return null;
};

/**
 * Identifies the image by its magic bytes (the Content-Type header is ignored) and
 * reads its pixel size from the header without decoding it.
 *
 * @returns {{type: 'jpeg'|'png', ext: 'jpg'|'png', width: number, height: number}}
 */
export const inspectImage = (buf) => {
    let type = null;
    let size = null;

    if (buf.length >= 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) {
        type = 'jpeg';
        size = jpegSize(buf);
    } else if (buf.length >= 8 && buf.subarray(0, 8).equals(PNG_SIGNATURE)) {
        type = 'png';
        size = pngSize(buf);
    }

    if (type === null) {
        throw new ValidationError('unsupported_image_type', 415);
    }
    if (size === null || size.width < 1 || size.height < 1) {
        throw new ValidationError('invalid_image');
    }
    if (size.width > LIMITS.maxImageSide || size.height > LIMITS.maxImageSide) {
        throw new ValidationError('image_dimensions_too_large', 413);
    }

    return { type, ext: type === 'jpeg' ? 'jpg' : 'png', width: size.width, height: size.height };
};

export const parseDuration = (raw) => {
    if (typeof raw !== 'string' || !/^\d{1,2}$/.test(raw)) {
        throw new ValidationError('invalid_duration');
    }
    const value = Number(raw);
    if (value < LIMITS.minDuration || value > LIMITS.maxDuration) {
        throw new ValidationError('invalid_duration');
    }
    return value;
};

const BASE64URL = /^[A-Za-z0-9_-]*$/;

/**
 * The headline travels base64url encoded in a header so that no UTF-8 ever
 * sits in a URL or a header value verbatim. Returns the raw decoded string
 * (not yet sanitized) or null when the header is absent.
 */
export const decodeHeadlineHeader = (raw) => {
    if (raw === undefined || raw === '') {
        return null;
    }
    if (typeof raw !== 'string' || raw.length > LIMITS.maxHeadlineChars * 4 * 2 || !BASE64URL.test(raw)) {
        throw new ValidationError('invalid_headline');
    }
    let text;
    try {
        text = new TextDecoder('utf-8', { fatal: true }).decode(Buffer.from(raw, 'base64url'));
    } catch {
        throw new ValidationError('invalid_headline');
    }
    if ([...text].length > LIMITS.maxHeadlineChars) {
        throw new ValidationError('headline_too_long');
    }
    return text;
};

const EMOJI = /[\p{Extended_Pictographic}\p{Emoji_Modifier}\p{Regional_Indicator}\u{FE0E}\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu;
const CONTROL = /[\p{Cc}\p{Cf}\u{2028}\u{2029}]/gu;

/**
 * Turns a decoded headline into plain displayable text: control and format
 * characters, emoji and every character the shipped font has no glyph for are
 * removed, whitespace is collapsed. `isCovered(codePoint)` tells whether the
 * font covers a code point.
 */
export const sanitizeHeadline = (text, isCovered) => {
    const stripped = text
        .replace(/[\t\n\r\u{00a0}\u{2000}-\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}]+/gu, ' ')
        .replace(CONTROL, '')
        .replace(EMOJI, '');
    let kept = '';
    for (const char of stripped) {
        if (isCovered(char.codePointAt(0))) {
            kept += char;
        }
    }
    return kept.replace(/ +/g, ' ').trim();
};
