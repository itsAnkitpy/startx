<?php

use App\Models\Tenant;
use Illuminate\Database\QueryException;

it('keeps one subdomain per client company', function () {
    Tenant::factory()->create(['slug' => 'meridian']);

    expect(fn () => Tenant::factory()->create(['slug' => 'meridian']))
        ->toThrow(QueryException::class);
});

it('records what a client company is', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Meridian Logistics',
        'slug' => 'meridian',
        'legal_name' => 'Meridian Logistics Private Limited',
    ]);

    expect($tenant->fresh())
        ->name->toBe('Meridian Logistics')
        ->slug->toBe('meridian')
        ->legal_name->toBe('Meridian Logistics Private Limited')
        ->country->toBe('IN')
        ->timezone->toBe('Asia/Kolkata')
        ->active->toBeTrue()
        ->and($tenant->onboarded_at)->not->toBeNull();
});
