export type SourceRect = {
    sx: number;
    sy: number;
    sw: number;
    sh: number;
};

export type Corner = 'nw' | 'ne' | 'sw' | 'se';

const DEFAULT_SELECTION_RATIO = 0.8;

const ENCODABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const EXTENSIONS: Record<string, string> = { 'image/jpeg': 'jpg', 'image/png': 'png', 'image/webp': 'webp' };

export const resolveOutputMime = (mimeType: string): string =>
    ENCODABLE_MIMES.includes(mimeType) ? mimeType : 'image/png';

export const resolveOutputFileName = (fileName: string, mime: string): string =>
    `${fileName.replace(/\.[^./]*$/, '') || 'image'}.${EXTENSIONS[mime] ?? 'png'}`;

export const containScale = (naturalWidth: number, naturalHeight: number, viewport: number): number => {
    if (naturalWidth <= 0 || naturalHeight <= 0) {
        return 1;
    }

    return Math.min(viewport / naturalWidth, viewport / naturalHeight);
};

// PATCH:story-photo-fit: `aspect` is width / height of the selection (1 = square avatar
// crop, 9 / 16 = story crop). `minSize` is always the minimum selection WIDTH.
export const defaultSelection = (naturalWidth: number, naturalHeight: number, aspect = 1): SourceRect => {
    const width = Math.min(naturalWidth, naturalHeight * aspect) * DEFAULT_SELECTION_RATIO;
    const height = width / aspect;

    return {
        sx: (naturalWidth - width) / 2,
        sy: (naturalHeight - height) / 2,
        sw: width,
        sh: height,
    };
};

export const clampSelection = (
    selection: SourceRect,
    naturalWidth: number,
    naturalHeight: number,
    minSize: number,
    aspect = 1,
): SourceRect => {
    const maxWidth = Math.min(naturalWidth, naturalHeight * aspect);
    const width = Math.min(Math.max(selection.sw, minSize), maxWidth);
    const height = width / aspect;
    const sx = Math.min(Math.max(selection.sx, 0), naturalWidth - width);
    const sy = Math.min(Math.max(selection.sy, 0), naturalHeight - height);

    return { sx, sy, sw: width, sh: height };
};

export const resizeSelection = (
    selection: SourceRect,
    corner: Corner,
    px: number,
    py: number,
    naturalWidth: number,
    naturalHeight: number,
    minSize: number,
    aspect = 1,
): SourceRect => {
    const right = selection.sx + selection.sw;
    const bottom = selection.sy + selection.sh;

    const anchorX = corner === 'nw' || corner === 'sw' ? right : selection.sx;
    const anchorY = corner === 'nw' || corner === 'ne' ? bottom : selection.sy;
    const horizontal = corner === 'ne' || corner === 'se' ? 1 : -1;
    const vertical = corner === 'sw' || corner === 'se' ? 1 : -1;

    const width = Math.max(horizontal * (px - anchorX), vertical * (py - anchorY) * aspect, minSize);
    const height = width / aspect;
    const sx = horizontal === 1 ? anchorX : anchorX - width;
    const sy = vertical === 1 ? anchorY : anchorY - height;

    return clampSelection({ sx, sy, sw: width, sh: height }, naturalWidth, naturalHeight, minSize, aspect);
};

export type NormalizedRect = {
    x: number;
    y: number;
    w: number;
    h: number;
};

const PRECISION = 10000;

// Source-pixel selection to the 0..1 rect stored in `story_crop`. Rounded to four
// decimals and clamped so x + w and y + h never exceed 1 (the server rejects that).
export const toNormalizedRect = (selection: SourceRect, naturalWidth: number, naturalHeight: number): NormalizedRect => {
    const x = Math.min(Math.max(Math.round((selection.sx / naturalWidth) * PRECISION) / PRECISION, 0), 1);
    const y = Math.min(Math.max(Math.round((selection.sy / naturalHeight) * PRECISION) / PRECISION, 0), 1);
    const w = Math.min(Math.round((selection.sw / naturalWidth) * PRECISION) / PRECISION, 1 - x);
    const h = Math.min(Math.round((selection.sh / naturalHeight) * PRECISION) / PRECISION, 1 - y);

    return { x, y, w, h };
};

export const fromNormalizedRect = (rect: NormalizedRect, naturalWidth: number, naturalHeight: number): SourceRect => ({
    sx: rect.x * naturalWidth,
    sy: rect.y * naturalHeight,
    sw: rect.w * naturalWidth,
    sh: rect.h * naturalHeight,
});

export const isNormalizedRect = (value: unknown): value is NormalizedRect => {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const rect = value as Record<string, unknown>;

    return ['x', 'y', 'w', 'h'].every((key) => typeof rect[key] === 'number' && Number.isFinite(rect[key]));
};
