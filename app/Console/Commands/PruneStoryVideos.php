<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\StoryVideoHost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-story-videos')]
#[Description('Delete hosted Instagram Story videos that a publish left behind for more than 24 hours')]
class PruneStoryVideos extends Command
{
    public function handle(StoryVideoHost $host): int
    {
        $this->info("Deleted {$host->prune()} hosted story video(s).");

        return self::SUCCESS;
    }
}
