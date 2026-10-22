// Input validation for the render service. Nothing from the caller is ever used as a path or URL.

export const LIMITS = Object.freeze({
    maxBodyBytes: 12 * 1024 * 1024,
    maxPhotoBytes: 8 * 1024 * 1024,
    maxLogoBytes: 2 * 1024 * 1024,
    maxSpecBytes: 8 * 1024,
    maxImageSide: 4096,
    maxLogoSide: 2000,
    minDuration: 3,
    maxDuration: 20,
});

export class ValidationError extends Error {
    constructor(code, status = 400, field = null) {
        super(code);
        this.code = code;
        this.status = status;
        this.field = field;
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
export const inspectImage = (buf, { maxSide = LIMITS.maxImageSide } = {}) => {
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
    if (size.width > maxSide || size.height > maxSide) {
        throw new ValidationError('image_dimensions_too_large', 413);
    }

    return { type, ext: type === 'jpeg' ? 'jpg' : 'png', width: size.width, height: size.height };
};
