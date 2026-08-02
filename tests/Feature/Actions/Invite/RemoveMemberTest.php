<?php

declare(strict_types=1);

use App\Actions\Invite\RemoveMember;
use App\Models\AccessToken;
use App\Models\User;

test('remove member clears current workspace when it was the removed membership', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$workspace, $other],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    RemoveMember::execute($workspace, $member->id);

    $member->refresh();

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect($member->current_workspace_id)->toBe($other->id);
    expect($member->account_id)->toBe($owner->account_id);
});

test('remove member deletes a user who loses their last account workspace', function () {
    [
        'member' => $member,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );

    RemoveMember::execute($workspace, $member->id);

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect(User::find($member->id))->toBeNull();
});

test('remove member prefers another workspace on the same account', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$sharedA, $sharedB],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    RemoveMember::execute($sharedA, $member->id);

    $member->refresh();

    expect($member->account_id)->toBe($owner->account_id);
    expect($member->current_workspace_id)->toBe($sharedB->id);
});

test('remove member revokes workspace-scoped api keys and mcp oauth tokens', function () {
    [
        'member' => $member,
        'shared_workspaces' => [$workspace, $other],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    $pat = $member->createToken('API Key')->token;
    $pat->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    $otherPat = $member->createToken('Other Key')->token;
    $otherPat->forceFill(['workspace_id' => $other->id])->saveQuietly();

    $mcp = mcpAccessToken($member, mcpOauthClient(), $workspace);

    RemoveMember::execute($workspace, $member->id);

    expect(AccessToken::query()->find($pat->id)->revoked)->toBeTrue();
    expect(AccessToken::query()->find($mcp->id)->revoked)->toBeTrue();
    expect(AccessToken::query()->find($otherPat->id)->revoked)->toBeFalse();
    expect(User::find($member->id))->not->toBeNull();
});
