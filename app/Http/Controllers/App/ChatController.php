<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('view', $workspace);

        return Inertia::render('chat/Index', [
            'threads' => [],
        ]);
    }
}
