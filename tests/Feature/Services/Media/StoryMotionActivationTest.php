<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Services\Media\StoryMotionRenderer;

// PATCH:story-motion-settings: Story videos run only when the installation (switch, URL, token) AND the workspace say yes.

$matrix = [];
foreach ([true, false] as $switch) {
    foreach (['http://story-render:3000', ''] as $url) {
        foreach (['secret', ''] as $token) {
            foreach ([true, false, null] as $workspaceEnabled) {
                $installation = $switch && $url !== '' && $token !== '';
                $reason = match (true) {
                    ! $switch => 'disabled',
                    $url === '' => 'no_url',
                    $token === '' => 'no_token',
                    $workspaceEnabled !== true => 'workspace_off',
                    default => null,
                };
                $matrix[sprintf('switch=%s url=%s token=%s workspace=%s', var_export($switch, true), $url === '' ? 'empty' : 'set', $token === '' ? 'empty' : 'set', var_export($workspaceEnabled, true))] = [$switch, $url, $token, $workspaceEnabled, $installation, $reason];
            }
        }
    }
}

test('activation matrix: every combination of switch, URL, token and workspace setting gives exactly one reason', function (bool $switch, string $url, string $token, ?bool $workspaceEnabled, bool $installation, ?string $reason) {
    config()->set('trypost.platforms.facebook.story_motion', ['enabled' => $switch, 'url' => $url, 'token' => $token, 'timeout_seconds' => 255]);
    $workspace = Workspace::factory()->create(['story_motion_settings' => $workspaceEnabled === null ? null : ['enabled' => $workspaceEnabled]]);
    $renderer = app(StoryMotionRenderer::class);

    expect($renderer->isAvailable())->toBe($installation)
        ->and($renderer->skipReason($workspace))->toBe($reason)
        ->and($renderer->isEnabledFor($workspace))->toBe($reason === null);
})->with($matrix);

test('activation: the shipped defaults are off at every level', function () {
    expect(config('trypost.platforms.facebook.story_motion.enabled'))->toBeFalse()
        ->and(app(StoryMotionRenderer::class)->isAvailable())->toBeFalse()
        ->and(Workspace::factory()->create()->story_motion_settings)->toBeNull();
});

test('activation: the workspace setting is read per workspace, one workspace on does not turn the other on', function () {
    config()->set('trypost.platforms.facebook.story_motion', ['enabled' => true, 'url' => 'http://story-render:3000', 'token' => 'secret', 'timeout_seconds' => 255]);
    $on = Workspace::factory()->create(['story_motion_settings' => ['enabled' => true]]);
    $off = Workspace::factory()->create();
    $renderer = app(StoryMotionRenderer::class);

    expect($renderer->isEnabledFor($on))->toBeTrue()->and($renderer->isEnabledFor($off))->toBeFalse();
});
