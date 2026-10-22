// Turns user text into plain displayable text. Nothing here builds markup: the composition receives
// the result as data and sets it with textContent.

// (c), (r) and the trademark sign are pictographic by Unicode, but plain text for us and covered by the fonts.
const EMOJI = /(?![\u{00A9}\u{00AE}\u{2122}])[\p{Extended_Pictographic}\p{Emoji_Modifier}\p{Regional_Indicator}\u{FE0E}\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu;
const CONTROL = /[\p{Cc}\p{Cf}\u{2028}\u{2029}]/gu;
const SPACES = /[\t\n\r\u{00a0}\u{2000}-\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}]+/gu;

/** Length in characters (code points), the way the limits count. */
export const textLength = (text) => [...text].length;

/** Normalize layout/control characters without silently removing unsupported letters or emoji. */
export const normalizeText = (text) => text.normalize('NFC').replace(SPACES, ' ').replace(CONTROL, '').replace(/ +/g, ' ').trim();

/**
 * Plain displayable text: control and format characters, emoji and every character the chosen font has
 * no glyph for are removed, whitespace is collapsed. `isCovered(codePoint)` tells whether the font
 * covers a code point.
 */
export const sanitizeText = (text, isCovered) => {
    const stripped = text.replace(SPACES, ' ').replace(CONTROL, '').replace(EMOJI, '');
    let kept = '';
    for (const char of stripped) {
        if (isCovered(char.codePointAt(0))) {
            kept += char;
        }
    }

    return kept.replace(/ +/g, ' ').trim();
};

// One accent word is marked as *word* (trailing punctuation allowed), tested token by token.
const ACCENT_TOKEN = /^\*.+\*[!?.,]*$/u;

/** The text without the accent marks, used for length limits. */
export const withoutAccentMarks = (text) => text.replaceAll('*', '');

/**
 * Splits a headline into words and marks the first `*word*` as the accent word. Every `*` is removed.
 * An unpaired or empty mark (`*a`, `**`) marks nothing.
 *
 * @returns {Array<{t: string, a: boolean}>}
 */
export const headlineTokens = (text, isCovered) => {
    const tokens = [];
    let accentTaken = false;

    for (const raw of text.replace(SPACES, ' ').split(' ')) {
        if (raw === '') {
            continue;
        }
        const isAccent = !accentTaken && ACCENT_TOKEN.test(raw);
        const word = sanitizeText(withoutAccentMarks(raw), isCovered);
        if (word === '') {
            continue;
        }
        if (isAccent) {
            accentTaken = true;
        }
        tokens.push({ t: word, a: isAccent });
    }

    return tokens;
};

/** A subline as parts around " · " separators, so the dots can take the accent colour. */
export const sublineParts = (text, isCovered) =>
    sanitizeText(text, isCovered)
        .replace(/^[\u00b7 ]+|[\u00b7 ]+$/gu, '')
        .split(' · ')
        .map((part) => part.trim())
        .filter((part) => part !== '');
