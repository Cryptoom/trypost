<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('shares the onboarding residual progress for subscribed accounts', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('onboardingResidual.completed', 0)
            ->where('onboardingResidual.total', 3)
        );
});

test('does not share the onboarding residual state after dismissal', function () {
    $this->user->account->update(['onboarding_dismissed_at' => now()]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingResidual', false));
});
