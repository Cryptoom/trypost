<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentStreamer;
use App\Models\Workspace;

// PATCH:aig-01 The dialog previews the raw stream, so the streamer must ask
// for plain post text. Asking for a JSON object made the preview depend on
// the model returning bare, parseable JSON (code fences or a preamble left
// the preview empty at "..." even though the text was generated).
test('streamer instructions ask for plain post text, not a JSON object', function () {
    $agent = new PostContentStreamer(workspace: Workspace::factory()->create());

    $instructions = $agent->instructions();

    expect($instructions)
        ->not->toContain('JSON object')
        ->not->toContain('image_keywords')
        ->toContain('Output only the post text');
});
