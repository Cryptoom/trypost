<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceConversation\Status;
use App\Models\WorkspaceConversation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Returns conversations stuck in-progress back to idle.
 *
 * Task 7 streams the chat agent inside an HTTP request. If the worker is
 * killed mid-turn, nothing else resets the conversation's status, and every
 * future message to it would be rejected with a 409 forever.
 *
 * The release update goes through toBase() so it stays a status-only
 * correction: Eloquent's Builder::update() would otherwise restamp
 * `updated_at` on every released row, and WorkspaceConversation::
 * scopeListable() sorts the sidebar by that column — silently bumping a
 * long-forgotten, already-titled conversation to the top.
 */
#[Signature('chat:release-stalled')]
#[Description('Return conversations stuck in progress back to idle so the user can send again')]
class ReleaseStalledConversations extends Command
{
    public function handle(): int
    {
        $released = WorkspaceConversation::query()
            ->where('status', Status::InProgress)
            ->where('updated_at', '<', now()->subMinutes(10))
            ->toBase()
            ->update(['status' => Status::Idle]);

        $this->info("Released {$released} stalled conversation(s).");

        return self::SUCCESS;
    }
}
