<?php

declare(strict_types=1);

namespace App\Ai\Tools;

/**
 * Base for every chat tool that mutates workspace data.
 *
 * Extending this class is the whole opt-in: the refusal itself lives in
 * {@see WorkspaceTool::handle()}, so a future write tool cannot forget to
 * authorize as long as it extends this instead of WorkspaceTool. The
 * `every write tool extends WorkspaceWriteTool` invariant is pinned by
 * tests/Feature/Ai/Tools/WriteToolAuthorizationTest.php, which walks the
 * agent's own tool list rather than a hand-kept copy of it.
 */
abstract class WorkspaceWriteTool extends WorkspaceTool
{
    final protected function authorizesWrites(): bool
    {
        return true;
    }
}
