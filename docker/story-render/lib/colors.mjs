// WCAG relative luminance helpers for the badge text colour.

const channel = (value) => {
    const c = value / 255;

    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

export const luminance = (hex) => {
    const n = Number.parseInt(hex.slice(1), 16);

    return 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
};

export const contrast = (a, b) => {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
};

export const NAVY = '#0A2A3D';

/** Dark navy or white, whichever reads better on the accent colour. */
export const textOn = (hex) => (contrast(hex, NAVY) >= contrast(hex, '#FFFFFF') ? NAVY : '#FFFFFF');
