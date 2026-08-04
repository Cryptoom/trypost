<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;
use Database\Seeders\DatabaseSeeder;

test('database seeder creates the workspace plan when not self hosted', function () {
    Plan::query()->delete();
    config(['trypost.self_hosted' => false]);

    $this->seed(DatabaseSeeder::class);

    expect(Plan::where('slug', Slug::Workspace)->exists())->toBeTrue();
});

test('database seeder creates the workspace plan when self hosted', function () {
    Plan::query()->delete();
    config(['trypost.self_hosted' => true]);

    $this->seed(DatabaseSeeder::class);

    expect(Plan::where('slug', Slug::Workspace)->exists())->toBeTrue();
});
