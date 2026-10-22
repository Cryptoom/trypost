<?php

declare(strict_types=1);

use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

// PATCH:story-motion-settings

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_20_120000_add_story_motion_settings_to_workspaces_table.php');
});

test('story motion settings migration: existing and new workspaces start with NULL, which reads as off', function () {
    expect(Schema::hasColumn('workspaces', 'story_motion_settings'))->toBeTrue();

    $workspace = Workspace::factory()->create();

    expect($workspace->fresh()->story_motion_settings)->toBeNull();
});

test('story motion settings migration: down() removes the column and up() adds it again without touching other data', function () {
    $workspace = Workspace::factory()->create(['name' => 'Keep me']);

    $this->migration->down();
    expect(Schema::hasColumn('workspaces', 'story_motion_settings'))->toBeFalse();

    $this->migration->up();
    expect(Schema::hasColumn('workspaces', 'story_motion_settings'))->toBeTrue()
        ->and($workspace->fresh()->name)->toBe('Keep me')
        ->and($workspace->fresh()->story_motion_settings)->toBeNull();
});

test('story motion settings migration: the column holds a JSON object and reads back as an array', function () {
    $workspace = Workspace::factory()->create();
    $workspace->update(['story_motion_settings' => ['enabled' => true, 'effects' => ['snap', 'drift']]]);

    // toEqual, not toBe: MySQL does not keep the key order of a JSON object.
    expect($workspace->fresh()->story_motion_settings)->toEqual(['enabled' => true, 'effects' => ['snap', 'drift']]);
});
