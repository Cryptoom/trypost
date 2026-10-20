<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Workspace;

use App\Support\StoryMotionSettingsRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH:story-motion-settings: the workspace's Story video settings. The rules live
 * in StoryMotionSettingsRules, shared with the MCP workspace tool.
 */
class UpdateStoryVideoSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return StoryMotionSettingsRules::rules();
    }
}
