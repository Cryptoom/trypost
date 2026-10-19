<?php

declare(strict_types=1);

use App\Services\Media\StoryVideoInspector;
use Tests\Support\StoryMotionFixtures;

beforeEach(function () {
    if (! StoryMotionFixtures::ffmpegAvailable()) {
        $this->markTestSkipped('ffmpeg or ffprobe is not installed on this machine.');
    }

    $this->files = [];
    $this->inspector = new StoryVideoInspector;
});

afterEach(function () {
    foreach ($this->files ?? [] as $file) {
        @unlink($file);
    }
});

test('a silent render output passes the guard when no audio is expected', function () {
    $this->files[] = $video = StoryMotionFixtures::video();

    expect($this->inspector->problem($video, 3, expectAudio: false))->toBeNull();
});

test('a final video with one aac track and the moov atom in front passes the guard', function () {
    $this->files[] = $video = StoryMotionFixtures::video(['audio' => true]);

    expect($this->inspector->problem($video, 3, expectAudio: true))->toBeNull();
});

test('a silent video is rejected as a final story video', function () {
    $this->files[] = $video = StoryMotionFixtures::video();

    expect($this->inspector->problem($video, 3, expectAudio: true))->toBe('audio_stream');
});

test('a video with audio is rejected as a silent render output', function () {
    $this->files[] = $video = StoryMotionFixtures::video(['audio' => true]);

    expect($this->inspector->problem($video, 3, expectAudio: false))->toBe('unexpected_audio');
});

test('wrong dimensions, frame rate and duration are rejected', function () {
    $this->files[] = $small = StoryMotionFixtures::video(['size' => '540x960']);
    $this->files[] = $slow = StoryMotionFixtures::video(['fps' => 25]);
    $this->files[] = $short = StoryMotionFixtures::video(['seconds' => 3]);

    expect($this->inspector->problem($small, 3, expectAudio: false))->toBe('dimensions')
        ->and($this->inspector->problem($slow, 3, expectAudio: false))->toBe('frame_rate')
        ->and($this->inspector->problem($short, 5, expectAudio: false))->toBe('duration')
        ->and($this->inspector->problem($short, 3, expectAudio: false))->toBeNull();
});

test('a video whose moov atom is at the end is rejected as a final story video', function () {
    $this->files[] = $video = StoryMotionFixtures::video(['audio' => true, 'faststart' => false]);

    expect($this->inspector->problem($video, 3, expectAudio: true))->toBe('moov_not_first');
});

test('files ffprobe cannot read are rejected', function () {
    $path = sys_get_temp_dir().'/sm01_'.uniqid().'.mp4';
    file_put_contents($path, 'not a video');
    $this->files[] = $path;

    expect($this->inspector->problem($path, 3, expectAudio: false))->toBe('unreadable')
        ->and($this->inspector->problem('/nonexistent/video.mp4', 3, expectAudio: false))->toBe('unreadable');
});
