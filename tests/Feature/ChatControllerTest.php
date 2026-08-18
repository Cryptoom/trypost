<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function (): void {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('guests cannot visit chat', function (): void {
    $this->get(route('app.chat'))
        ->assertRedirect(route('login'));
});

test('unsubscribed accounts are redirected to onboarding', function (): void {
    $this->user->account->subscriptions()->delete();

    $this->actingAs($this->user->fresh())
        ->get(route('app.chat'))
        ->assertRedirect(route('app.onboarding'));
});

test('subscribed owners can open the workspace chat', function (): void {
    $this->actingAs($this->user)
        ->get(route('app.chat'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/Index', false)
            ->where('conversations', []));
});

test('workspace members can open the workspace chat', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->get(route('app.chat'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/Index', false));
});

test('self hosted owners can open chat without a subscription', function (): void {
    config(['trypost.self_hosted' => true]);
    $this->user->account->subscriptions()->delete();

    $this->actingAs($this->user->fresh())
        ->get(route('app.chat'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('chat/Index', false));
});
