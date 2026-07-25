<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('inertia shares content type media rules for the frontend', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('contentTypeMediaRules')
            ->where('contentTypeMediaRules.instagram_reel.max_video_duration_sec', 900)
            ->where('contentTypeMediaRules.facebook_reel.max_video_duration_sec', 90)
            ->where('contentTypeMediaRules.tiktok_video.max_video_duration_sec', null)
        );
});
