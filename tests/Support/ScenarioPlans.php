<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The host's plan catalog as a Scenario lens prices it: a central `plans` table holding the two plans the Demo
 * blueprints name. `professional` carries a fee, seat overage and a metered usage fee, so a scenario month walks
 * every pricing path; `starter` is a flat fee.
 */
function seedScenarioPlans(bool $withStarter = true): void
{
    if (! Schema::hasTable('plans')) {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    $plans = ['professional' => ['Professional', [
        ['type' => 'SubscriptionFee', 'config' => ['monthly_price' => 99.0, 'annual_monthly_price' => 79.0]],
        ['type' => 'PerSeatFees', 'config' => ['included_seats' => 5, 'per_seat_price' => 12.0]],
        ['type' => 'PerMeterFees', 'config' => ['meter' => 'openai.tokens', 'strategy' => 'linear', 'params' => ['markup' => 1.5]]],
    ]]];

    if ($withStarter) {
        $plans['starter'] = ['Starter', [
            ['type' => 'SubscriptionFee', 'config' => ['monthly_price' => 29.0, 'annual_monthly_price' => 24.0]],
        ]];
    }

    foreach ($plans as $slug => [$name, $components]) {
        DB::table('plans')->insert([
            'id' => sprintf('00000000-0000-0000-0000-%012d', crc32($slug)),
            'name' => $name,
            'slug' => $slug,
            'settings' => json_encode(['components' => $components]),
        ]);
    }
}
