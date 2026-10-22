<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Exceptions\Media\ImageTooLargeException;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brand\LogoAttacher;
use App\Support\StoryMotionLogo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// PATCH:story-motion-settings: a logo is scaled to fit the Story video layer when it is saved (Olli, 19.10.2026,
// "Automatisch beim Hochladen verkleinern, gilt für alle Marken"), and a stored logo that does not fit is reported.

/**
 * A real PNG with a transparent background and a coloured ellipse.
 */
function sm02LogoPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.8), (int) ($height * 0.8), imagecolorallocatealpha($image, 200, 30, 30, 0));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A JPEG full of random noise: the worst case for the file size.
 */
function sm02NoisyJpegFile(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    mt_srand(7);
    for ($x = 0; $x < $width; $x += 2) {
        for ($y = 0; $y < $height; $y += 2) {
            imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'sm02_noise_').'.jpg';
    imagejpeg($image, $path, 100);

    return $path;
}

/**
 * A one-colour progressive JPEG of 9000 x 9000 px (81 megapixels): under 1 MB on disk, but about 324 MB once
 * GD decodes it.
 */
function sm02HugeJpeg(): string
{
    $image = imagecreatetruecolor(9000, 9000);
    imagefill($image, 0, 0, imagecolorallocate($image, 128, 128, 128));
    imageinterlace($image, true);
    ob_start();
    imagejpeg($image, null, 75);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** A real compressed image with a red left half and blue right half, optionally with JPEG EXIF 6. */
function sm02SolidLogo(int $width, int $height, string $format, bool $exif6 = false): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));
    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        default => imagejpeg($image, null, 90),
    };
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    if ($exif6) {
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x112, 3, 1, 6)."\0\0".pack('V', 0);
        $bytes = substr($bytes, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2);
    }

    return $bytes;
}

beforeEach(function () {
    Storage::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->upload = fn (string $name, string $bytes) => $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->createWithContent($name, $bytes),
    ])->assertSessionHasNoErrors();

    $this->storedLogo = fn (): Media => $this->workspace->fresh()->getFirstMedia('logo');
});

test('logo upload: a 4000 x 1528 logo is scaled to at most 2000 px, keeps its proportions and fits the Story video', function () {
    ($this->upload)('logo.png', sm02LogoPng(4000, 1528));

    $logo = ($this->storedLogo)();

    expect($logo->meta['width'])->toBe(2000)
        ->and($logo->meta['height'])->toBe(764)
        ->and($logo->mime_type)->toBe('image/jpeg')
        ->and($logo->size)->toBeLessThanOrEqual(StoryMotionLogo::MAX_BYTES)
        ->and(StoryMotionLogo::usable($logo->size, $logo->meta['width'], $logo->meta['height'], $logo->mime_type))->toBeTrue();

    $bytes = Storage::get($logo->path);
    expect(str_starts_with($bytes, "\xFF\xD8\xFF"))->toBeTrue()
        ->and(getimagesizefromstring($bytes)[0])->toBe(2000)
        ->and(strlen($bytes))->toBe($logo->size);
});

test('logo upload: a tall logo is limited on its height', function () {
    ($this->upload)('logo.png', sm02LogoPng(900, 3000));

    $logo = ($this->storedLogo)();

    expect($logo->meta['height'])->toBe(2000)->and($logo->meta['width'])->toBe(600);
});

test('logo upload: a logo that already fits is not scaled', function () {
    ($this->upload)('logo.png', sm02LogoPng(600, 200));

    $logo = ($this->storedLogo)();

    expect($logo->meta['width'])->toBe(600)->and($logo->meta['height'])->toBe(200);
});

test('logo upload: a logo of exactly 2000 px is kept as it is', function () {
    ($this->upload)('logo.png', sm02LogoPng(2000, 800));

    expect(($this->storedLogo)()->meta)->toMatchArray(['width' => 2000, 'height' => 800]);
});

test('logo upload: a transparent PNG still goes through the existing JPEG conversion, flat on white, not black', function () {
    ($this->upload)('logo.png', sm02LogoPng(3000, 1000));

    $image = imagecreatefromstring(Storage::get(($this->storedLogo)()->path));
    $corner = imagecolorsforindex($image, imagecolorat($image, 3, 3));

    expect($corner['red'])->toBeGreaterThan(240)->and($corner['green'])->toBeGreaterThan(240)->and($corner['blue'])->toBeGreaterThan(240);
});

test('logo upload: an oversized JPEG is scaled and a JPEG that fits is stored byte for byte', function () {
    $big = sm02NoisyJpegFile(3000, 2400);
    $this->workspace->addMediaFromPath($big, 'big.jpg', 'logo');
    $scaled = ($this->storedLogo)();

    expect($scaled->meta['width'])->toBe(2000)->and($scaled->meta['height'])->toBe(1600)->and($scaled->mime_type)->toBe('image/jpeg');

    $small = sm02NoisyJpegFile(400, 300);
    $original = file_get_contents($small);
    ($this->upload)('small.jpg', $original);

    expect(Storage::get(($this->storedLogo)()->path))->toBe($original);
    @unlink($big);
    @unlink($small);
});

test('logo upload: a JPEG that is within 2000 px but over 2 MB is brought under 2 MB, not stored as it is', function () {
    $path = sm02NoisyJpegFile(1990, 1990);
    expect(filesize($path))->toBeGreaterThan(StoryMotionLogo::MAX_BYTES);

    $this->workspace->addMediaFromPath($path, 'heavy.jpg', 'logo');
    $logo = ($this->storedLogo)();

    expect($logo->size)->toBeLessThanOrEqual(StoryMotionLogo::MAX_BYTES)
        ->and($logo->meta['width'])->toBe(1990)
        ->and($logo->meta['height'])->toBe(1990);
    @unlink($path);
});

test('logo upload: a noisy logo is brought under 2 MB by lowering the quality, never by cutting pixels it does not have to', function () {
    $path = sm02NoisyJpegFile(2600, 2600);
    $this->workspace->addMediaFromPath($path, 'noise.jpg', 'logo');
    $logo = ($this->storedLogo)();

    expect(filesize($path))->toBeGreaterThan(StoryMotionLogo::MAX_BYTES)
        ->and($logo->size)->toBeLessThanOrEqual(StoryMotionLogo::MAX_BYTES)
        ->and($logo->meta['width'])->toBe(2000)
        ->and($logo->meta['height'])->toBe(2000);
    @unlink($path);
});

test('logo from the website autofill is scaled the same way', function () {
    $png = sm02LogoPng(4000, 1528);
    Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($png)])]);

    expect(app(LogoAttacher::class)->attach($this->workspace, 'https://93.184.216.34/logo.png'))->toBeTrue();

    $logo = ($this->storedLogo)();

    expect($logo->meta['width'])->toBe(2000)->and($logo->meta['height'])->toBe(764)
        ->and(StoryMotionLogo::usable($logo->size, $logo->meta['width'], $logo->meta['height'], $logo->mime_type))->toBeTrue();
});

test('logo upload: other media collections are not scaled', function () {
    $this->workspace->addMediaFromPath(tap(tempnam(sys_get_temp_dir(), 'sm02_a_').'.png', fn ($p) => file_put_contents($p, sm02LogoPng(3000, 1000))), 'asset.png', 'assets');

    expect($this->workspace->fresh()->getFirstMedia('assets')->meta)->toMatchArray(['width' => 3000, 'height' => 1000]);
});

test('logo upload: a GIF is stored as it is, never scaled, and reported as not usable', function () {
    $gif = tempnam(sys_get_temp_dir(), 'sm02_g_').'.gif';
    $image = imagecreatetruecolor(2400, 800);
    imagegif($image, $gif);
    $this->workspace->addMediaFromPath($gif, 'logo.gif', 'logo');
    $logo = ($this->storedLogo)();

    expect($logo->mime_type)->toBe('image/gif')
        ->and($logo->meta['width'])->toBe(2400)
        ->and(StoryMotionLogo::usable($logo->size, $logo->meta['width'], $logo->meta['height'], $logo->mime_type))->toBeFalse();
    @unlink($gif);
});

test('brand page: a logo that was stored before and is 4000 x 1528 reports logoUsable false until it is uploaded again', function () {
    $this->workspace->addMediaFromStoredPath('medias/old-logo.jpg', 'logo.jpg', 'image/jpeg', 647145, 'logo', ['width' => 4000, 'height' => 1528]);

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page
        ->where('storyMotion.hasLogo', true)
        ->where('storyMotion.logoUsable', false));

    ($this->upload)('logo.png', sm02LogoPng(4000, 1528));

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page
        ->where('storyMotion.hasLogo', true)
        ->where('storyMotion.logoUsable', true));
});

test('brand page: a stored GIF or ICO logo reports logoUsable false', function (string $mime) {
    $this->workspace->addMediaFromStoredPath('medias/old-logo.bin', 'logo.bin', $mime, 4000, 'logo', ['width' => 64, 'height' => 64]);

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page
        ->where('storyMotion.hasLogo', true)
        ->where('storyMotion.logoUsable', false));
})->with(['image/gif', 'image/x-icon']);

test('brand page: no logo means hasLogo false and logoUsable false', function () {
    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page
        ->where('storyMotion.hasLogo', false)
        ->where('storyMotion.logoUsable', false));
});

test('brand page: logoUsable is judged from stored values, without reading the file', function () {
    $this->workspace->addMediaFromStoredPath('medias/does-not-exist.jpg', 'logo.jpg', 'image/jpeg', 1000, 'logo', ['width' => 800, 'height' => 300]);

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page->where('storyMotion.logoUsable', true));
});

test('logo upload: a JPEG above the pixel ceiling is refused with 422 before it is decoded, and the old logo stays', function () {
    ($this->upload)('old.png', sm02LogoPng(400, 200));
    $old = ($this->storedLogo)();
    $huge = sm02HugeJpeg();

    expect(strlen($huge))->toBeLessThan(1024 * 1024);

    $this->actingAs($this->user)->postJson(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->createWithContent('huge.jpg', $huge),
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['photo' => '8']);

    expect($this->workspace->fresh()->getMedia('logo')->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::exists($old->path))->toBeTrue();
});

test('logo upload: the refusal names the limit in the language of the user', function () {
    $this->user->update(['locale' => 'de']);

    $this->actingAs($this->user)->postJson(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->createWithContent('huge.jpg', sm02HugeJpeg()),
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['photo' => 'bis 8 Megapixel']);
});

test('logo from the website autofill: a JPEG above the pixel ceiling is skipped and the old logo stays', function () {
    ($this->upload)('old.png', sm02LogoPng(400, 200));
    $old = ($this->storedLogo)();
    $huge = sm02HugeJpeg();
    Http::fake(['*' => Http::response($huge, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($huge)])]);

    expect(app(LogoAttacher::class)->attach($this->workspace, 'https://93.184.216.34/logo.jpg'))->toBeFalse()
        ->and($this->workspace->fresh()->getMedia('logo')->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::exists($old->path))->toBeTrue();
});

test('logo upload: when storing the new logo fails, the old logo stays in the database and in storage', function () {
    ($this->upload)('old.png', sm02LogoPng(400, 200));
    $old = ($this->storedLogo)();
    Media::creating(fn () => throw new RuntimeException('media store down'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->createWithContent('new.png', sm02LogoPng(500, 200)),
    ]))->toThrow(RuntimeException::class, 'media store down');

    expect($this->workspace->fresh()->getMedia('logo')->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::exists($old->path))->toBeTrue();
});

test('logo from the website autofill: when storing the new logo fails, the old logo stays', function () {
    ($this->upload)('old.png', sm02LogoPng(400, 200));
    $old = ($this->storedLogo)();
    $png = sm02LogoPng(500, 200);
    Http::fake(['*' => Http::response($png, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($png)])]);
    Media::creating(fn () => throw new RuntimeException('media store down'));

    expect(app(LogoAttacher::class)->attach($this->workspace, 'https://93.184.216.34/logo.png'))->toBeFalse()
        ->and($this->workspace->fresh()->getMedia('logo')->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::exists($old->path))->toBeTrue();
});

test('logo upload: a successful upload replaces the old logo, in the database and in storage', function () {
    ($this->upload)('old.png', sm02LogoPng(400, 200));
    $old = ($this->storedLogo)();

    ($this->upload)('new.png', sm02LogoPng(500, 200));
    $new = ($this->storedLogo)();

    expect($this->workspace->fresh()->getMedia('logo')->pluck('id')->all())->toBe([$new->id])
        ->and($new->id)->not->toBe($old->id)
        ->and(Storage::exists($old->path))->toBeFalse()
        ->and(Storage::exists($new->path))->toBeTrue();
});

test('logo upload: an image whose dimensions cannot be read is stored as it is and never handed to the decoder', function () {
    $path = tempnam(sys_get_temp_dir(), 'sm02_bad_').'.png';
    file_put_contents($path, str_repeat('not an image ', 50));
    Log::spy();

    $this->workspace->addMediaFromPath($path, 'logo.png', 'logo', [], null, 'image/png');
    $logo = ($this->storedLogo)();

    expect(Storage::get($logo->path))->toBe(file_get_contents($path))
        ->and($logo->mime_type)->toBe('image/png');
    Log::shouldNotHaveReceived('warning');
    @unlink($path);
});

test('logo upload: interleaved replacements leave exactly one logo without changing assets', function () {
    $transactionLevel = $this->workspace->getConnection()->transactionLevel();
    ($this->upload)('old.png', sm02LogoPng(100, 50));
    $locks = 0;
    $this->workspace->getConnection()->listen(function (QueryExecuted $query) use (&$locks) {
        if (preg_match('/\bfor update\b/i', $query->sql) === 1) {
            $locks++;
        }
    });
    $asset = $this->workspace->addMedia(UploadedFile::fake()->createWithContent('asset.png', sm02LogoPng(80, 40)), 'assets');
    expect($locks)->toBe(0);
    $other = $this->workspace->fresh();
    $interleaved = false;
    Media::creating(function (Media $media) use ($other, &$interleaved) {
        if ($media->original_filename === 'first.png' && ! $interleaved) {
            $interleaved = true;
            $other->addMedia(UploadedFile::fake()->createWithContent('second.png', sm02LogoPng(90, 40)), 'logo');
        }
    });

    $first = $this->workspace->addMedia(UploadedFile::fake()->createWithContent('first.png', sm02LogoPng(100, 40)), 'logo');

    expect($interleaved)->toBeTrue()
        ->and($this->workspace->getMedia('logo')->pluck('id')->all())->toBe([$first->id])
        ->and(Storage::exists($first->path))->toBeTrue()
        ->and($this->workspace->getMedia('assets')->pluck('id')->all())->toBe([$asset->id])
        ->and(Storage::exists($asset->path))->toBeTrue();

    if ($this->workspace->getConnection()->getDriverName() !== 'sqlite') {
        expect($locks)->toBe(4);
    }

    expect($this->workspace->getConnection()->transactionLevel())->toBe($transactionLevel);
});

test('logo upload: PNG and WebP above the pixel ceiling retain the old logo', function (string $format) {
    ($this->upload)('old.png', sm02LogoPng(100, 50));
    $old = ($this->storedLogo)();
    $bytes = sm02SolidLogo(4001, 2000, $format);
    Log::spy();

    $this->actingAs($this->user)->postJson(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->createWithContent('large.'.$format, $bytes),
    ])->assertUnprocessable()->assertJsonValidationErrors('photo');

    expect($this->workspace->getMedia('logo')->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::exists($old->path))->toBeTrue();
    Log::shouldNotHaveReceived('warning');
})->with(['png', 'webp']);

test('logo upload: exactly the pixel ceiling with EXIF 6 keeps the correct orientation and proportions', function () {
    ($this->upload)('oriented.jpg', sm02SolidLogo(4000, 2000, 'jpeg', true));
    $logo = ($this->storedLogo)();
    $image = imagecreatefromstring(Storage::get($logo->path));
    $top = imagecolorsforindex($image, imagecolorat($image, 500, 250));
    $bottom = imagecolorsforindex($image, imagecolorat($image, 500, 1750));

    expect($logo->meta['width'])->toBe(1000)
        ->and($logo->meta['height'])->toBe(2000)
        ->and($top['red'])->toBeGreaterThan(200)
        ->and($top['blue'])->toBeLessThan(50)
        ->and($bottom['blue'])->toBeGreaterThan(200)
        ->and($bottom['red'])->toBeLessThan(50);
    imagedestroy($image);
});

test('logo direct path: one pixel above the ceiling is refused before decoding the payload', function () {
    // A valid PNG dimension header is sufficient for preflight; no enormous raster is allocated by the test.
    $ihdr = 'IHDR'.pack('NNCCCCC', StoryMotionLogo::MAX_DECODE_PIXELS + 1, 1, 8, 2, 0, 0, 0);
    $header = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    $path = tempnam(sys_get_temp_dir(), 'tps-sm-02-r3-pixel-');
    file_put_contents($path, $header);
    Log::spy();

    try {
        expect(getimagesize($path)[0])->toBe(StoryMotionLogo::MAX_DECODE_PIXELS + 1);
        expect(fn () => $this->workspace->addMediaFromPath($path, 'large.png', 'logo', [], null, 'image/png'))
            ->toThrow(ImageTooLargeException::class);
        expect($this->workspace->getMedia('logo')->count())->toBe(0);
        Log::shouldNotHaveReceived('warning');
    } finally {
        unlink($path);
    }
});

test('logo decode: current memory limit and allocations are checked for upload, autofill and direct paths', function (string $entry) {
    ($this->upload)('old.png', sm02LogoPng(100, 50));
    $old = ($this->storedLogo)();
    $bytes = sm02SolidLogo(4000, 2000, 'png');
    $path = tempnam(sys_get_temp_dir(), 'tps-sm-02-r3-budget-');
    file_put_contents($path, $bytes);
    Http::fake(['*' => Http::response($bytes, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($bytes)])]);
    $limit = ini_get('memory_limit');
    $reduced = memory_get_usage(true) + 64 * 1024 * 1024 + 8_000_000 * 12;
    ini_set('memory_limit', (string) $reduced);
    Log::spy();

    try {
        if ($entry === 'upload') {
            $this->actingAs($this->user)->postJson(route('app.workspace.upload-logo'), [
                'photo' => UploadedFile::fake()->createWithContent('new.png', $bytes),
            ])->assertUnprocessable()->assertJsonValidationErrors('photo');
        } elseif ($entry === 'autofill') {
            expect(app(LogoAttacher::class)->attach($this->workspace, 'https://93.184.216.34/logo.png'))->toBeFalse();
        } else {
            expect(fn () => $this->workspace->addMediaFromPath($path, 'new.png', 'logo', [], null, 'image/png'))
                ->toThrow(ImageTooLargeException::class);
        }

        expect(ini_get('memory_limit'))->toBe((string) $reduced)
            ->and($this->workspace->getMedia('logo')->pluck('id')->all())->toBe([$old->id])
            ->and(Storage::exists($old->path))->toBeTrue();
        Log::shouldNotHaveReceived('warning');
    } finally {
        ini_set('memory_limit', $limit);
        unlink($path);
    }
})->with(['upload', 'autofill', 'direct']);
