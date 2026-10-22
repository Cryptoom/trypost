import assert from 'node:assert/strict';
import test from 'node:test';
import { parseMultipart } from '../lib/multipart.mjs';
import { LIMITS, ValidationError } from '../lib/validate.mjs';
import { fakeJpeg, fakePng, multipartBody, validSpec } from '../testkit/helpers.mjs';

const BOUNDARY = 'story-boundary-8f3a91c2d7';
const TYPE = `multipart/form-data; boundary=${BOUNDARY}`;

const parse = ({ body, contentType = TYPE }) => parseMultipart(body, contentType);
const rejects = (promise, code, status) =>
    assert.rejects(promise, (error) => error instanceof ValidationError && error.code === code && (status === undefined || error.status === status), `expected ${code}`);

const raw = (...parts) => Buffer.from(`${parts.join('')}--${BOUNDARY}--\r\n`);
const fileHead = (name) => `--${BOUNDARY}\r\nContent-Disposition: form-data; name="${name}"; filename="${name}.bin"\r\nContent-Type: application/octet-stream\r\n\r\n`;
const textHead = (name) => `--${BOUNDARY}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n`;

test('multipart limits: a valid body is read, binary content with line breaks and dashes stays intact', async () => {
    // A photo that contains CRLF, dashes and a boundary-like line, like real binary data can.
    const photo = Buffer.concat([fakeJpeg(1080, 1920), Buffer.from('\r\n--not-the-boundary\r\n--\r\n\u0000�'), Buffer.alloc(5000, 0x2d)]);
    const logo = fakePng(300, 100);
    const spec = JSON.stringify(validSpec({ headline: 'Ελληνικά ПРОБУЖДАЕТСЯ Größe ÄÖÜß', subline: '日本' }));
    const { body, contentType } = multipartBody({ photo, logo, spec });
    const parts = await parseMultipart(body, contentType);
    assert.ok(parts.photo.equals(photo), 'photo bytes changed');
    assert.ok(parts.logo.equals(logo), 'logo bytes changed');
    assert.equal(parts.spec, spec, 'UTF-8 in the spec changed');
});

test('multipart limits: the logo is optional', async () => {
    const parts = await parseMultipart(...Object.values(multipartBody()));
    assert.equal(parts.logo, null);
});

test('multipart limits: a missing photo is rejected', async () => {
    const { body, contentType } = multipartBody({ photo: null });
    await rejects(parseMultipart(body, contentType), 'photo_missing');
});

test('multipart limits: a missing spec is rejected', async () => {
    const { body, contentType } = multipartBody({ spec: null });
    await rejects(parseMultipart(body, contentType), 'spec_missing');
});

test('multipart limits: an unknown part name is rejected', async () => {
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', textHead('spec'), '{}\r\n', fileHead('extra'), 'x\r\n') }), 'unknown_part');
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', textHead('spec'), '{}\r\n', textHead('__proto__'), 'x\r\n') }), 'unknown_part');
});

test('multipart limits: the same part name twice is rejected', async () => {
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', fileHead('photo'), 'y\r\n', textHead('spec'), '{}\r\n') }), 'duplicate_part');
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', textHead('spec'), '{}\r\n', textHead('spec'), '{}\r\n') }), 'duplicate_part');
});

test('multipart limits: more than three parts are rejected', async () => {
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', fileHead('logo'), 'y\r\n', textHead('spec'), '{}\r\n', textHead('spec2'), '{}\r\n') }), 'too_many_parts');
});

test('multipart limits: the photo as a plain text field is rejected', async () => {
    await rejects(parse({ body: raw(textHead('photo'), 'not a file\r\n', textHead('spec'), '{}\r\n') }), 'photo_must_be_file');
});

test('multipart limits: the logo as a plain text field is rejected', async () => {
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', textHead('logo'), 'not a file\r\n', textHead('spec'), '{}\r\n') }), 'logo_must_be_file');
});

test('multipart limits: the spec sent as a file is rejected', async () => {
    await rejects(parse({ body: raw(fileHead('photo'), 'x\r\n', fileHead('spec'), '{}\r\n') }), 'spec_must_be_text');
});

test('multipart limits: a photo over 8 MB is rejected, exactly 8 MB is not', async () => {
    const ok = Buffer.alloc(LIMITS.maxPhotoBytes, 1);
    assert.equal((await parseMultipart(...Object.values(multipartBody({ photo: ok })))).photo.length, LIMITS.maxPhotoBytes);
    const { body, contentType } = multipartBody({ photo: Buffer.alloc(LIMITS.maxPhotoBytes + 1, 1) });
    await rejects(parseMultipart(body, contentType), 'photo_too_large', 413);
});

test('multipart limits: a logo over 2 MB is rejected, exactly 2 MB is not', async () => {
    const ok = Buffer.alloc(LIMITS.maxLogoBytes, 1);
    assert.equal((await parseMultipart(...Object.values(multipartBody({ logo: ok })))).logo.length, LIMITS.maxLogoBytes);
    const { body, contentType } = multipartBody({ logo: Buffer.alloc(LIMITS.maxLogoBytes + 1, 1) });
    await rejects(parseMultipart(body, contentType), 'logo_too_large', 413);
});

test('multipart limits: a spec over 8 KB is rejected, exactly 8 KB is not', async () => {
    const ok = 'x'.repeat(LIMITS.maxSpecBytes);
    assert.equal((await parseMultipart(...Object.values(multipartBody({ spec: ok })))).spec.length, LIMITS.maxSpecBytes);
    const { body, contentType } = multipartBody({ spec: 'x'.repeat(LIMITS.maxSpecBytes + 1) });
    await rejects(parseMultipart(body, contentType), 'spec_too_large', 413);
    // measured in bytes, not characters
    const wide = multipartBody({ spec: 'ä'.repeat(LIMITS.maxSpecBytes / 2 + 1) });
    await rejects(parseMultipart(wide.body, wide.contentType), 'spec_too_large', 413);
});

test('multipart limits: a missing or empty boundary is rejected', async () => {
    const { body } = multipartBody();
    await rejects(parseMultipart(body, 'multipart/form-data'), 'unsupported_media_type', 415);
    await rejects(parseMultipart(body, 'multipart/form-data;'), 'invalid_multipart');
    await rejects(parseMultipart(body, 'multipart/form-data; boundary='), 'invalid_multipart');
    await rejects(parseMultipart(body, 'multipart/form-data; boundary=wrong'), 'invalid_multipart');
});

test('multipart limits: a broken part header or a body without an end is rejected', async () => {
    await rejects(parse({ body: Buffer.from(`--${BOUNDARY}\r\nthis is not a header\r\n\r\nx\r\n--${BOUNDARY}--\r\n`) }), 'invalid_multipart');
    await rejects(parse({ body: Buffer.from(`--${BOUNDARY}\r\nContent-Disposition: form-data; name="photo"; filename="p"\r\n\r\nno end`) }), 'invalid_multipart');
    await rejects(parse({ body: Buffer.from('') }), 'invalid_multipart');
    await rejects(parse({ body: Buffer.from('garbage') }), 'invalid_multipart');
});

test('multipart limits: a part without a name is rejected', async () => {
    await rejects(parse({ body: Buffer.from(`--${BOUNDARY}\r\nContent-Disposition: form-data\r\n\r\nx\r\n--${BOUNDARY}--\r\n`) }), 'invalid_multipart');
});

test('multipart limits: a nested multipart part is no photo, no logo and no spec', async () => {
    const nested = `--${BOUNDARY}\r\nContent-Disposition: form-data; name="photo"\r\nContent-Type: multipart/mixed; boundary=inner\r\n\r\n--inner\r\nContent-Disposition: form-data; name="spec"\r\n\r\n{}\r\n--inner--\r\n`;
    await assert.rejects(parse({ body: raw(nested, textHead('spec'), '{}\r\n') }), (error) => error instanceof ValidationError, 'nested multipart must not pass');
});

test('multipart limits: any other content type is a 415', async () => {
    const { body } = multipartBody();
    for (const type of ['application/json', 'application/octet-stream', 'text/plain', 'multipart/mixed; boundary=x', 'image/jpeg', undefined, '']) {
        await rejects(parseMultipart(body, type), 'unsupported_media_type', 415);
    }
});

test('multipart limits: a part name with markup characters is rejected and never echoed', async () => {
    const error = await parse({ body: raw(fileHead('photo'), 'x\r\n', textHead('spec'), '{}\r\n', textHead('<script>alert(1)</script>'), 'x\r\n') }).catch((e) => e);
    assert.ok(error instanceof ValidationError);
    assert.equal(error.code, 'unknown_part');
    assert.equal(error.field, null);
});
