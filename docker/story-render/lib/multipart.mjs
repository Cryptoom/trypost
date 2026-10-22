import { LIMITS, ValidationError } from './validate.mjs';

const PART_LIMITS = Object.freeze({ photo: LIMITS.maxPhotoBytes, logo: LIMITS.maxLogoBytes, spec: LIMITS.maxSpecBytes });
const MAX_PARTS = 3;

/**
 * Parses a complete `multipart/form-data` body held in memory (the caller has already enforced the
 * overall byte limit) with the platform's own parser. Exactly the parts `photo`, optionally `logo`, and
 * `spec` are accepted, each once; `photo` and `logo` must be files, `spec` must be a text field.
 * Anything the parser cannot read (missing boundary, broken part headers, nested multipart) is a 400.
 *
 * @param {Buffer} body
 * @param {string | undefined} contentType the request's Content-Type header
 * @returns {Promise<{photo: Buffer, logo: Buffer | null, spec: string}>}
 */
export const parseMultipart = async (body, contentType) => {
    if (typeof contentType !== 'string' || !/^multipart\/form-data\s*;/i.test(contentType)) {
        throw new ValidationError('unsupported_media_type', 415);
    }

    let form;
    try {
        form = await new Response(body, { headers: { 'content-type': contentType } }).formData();
    } catch {
        throw new ValidationError('invalid_multipart');
    }

    const entries = [...form.entries()];
    if (entries.length > MAX_PARTS) {
        throw new ValidationError('too_many_parts');
    }

    const seen = new Set();
    const parts = {};
    for (const [name, value] of entries) {
        if (!Object.hasOwn(PART_LIMITS, name)) {
            throw new ValidationError('unknown_part');
        }
        if (seen.has(name)) {
            throw new ValidationError('duplicate_part', 400, name);
        }
        seen.add(name);

        if (name === 'spec') {
            if (typeof value !== 'string') {
                throw new ValidationError('spec_must_be_text');
            }
            if (Buffer.byteLength(value, 'utf8') > PART_LIMITS.spec) {
                throw new ValidationError('spec_too_large', 413);
            }
            parts.spec = value;
            continue;
        }
        if (typeof value === 'string') {
            throw new ValidationError(`${name}_must_be_file`);
        }
        if (value.size > PART_LIMITS[name]) {
            throw new ValidationError(`${name}_too_large`, 413);
        }
        parts[name] = Buffer.from(await value.arrayBuffer());
    }

    if (!parts.photo) {
        throw new ValidationError('photo_missing');
    }
    if (parts.spec === undefined) {
        throw new ValidationError('spec_missing');
    }

    return { photo: parts.photo, logo: parts.logo ?? null, spec: parts.spec };
};
