<?php

namespace Tests\Fixtures;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stands in for the real background work — module 06's reminder pass, module 09's
 * document generation. The point it proves is the shape: a job carries the client
 * company it belongs to and names it explicitly, rather than reading it from a
 * signed-in user, because there is no signed-in user here.
 */
class CountFixturesJob implements ShouldQueue
{
    use Queueable;

    public static ?int $counted = null;

    public function __construct(public int $tenantId) {}

    public function handle(): void
    {
        self::$counted = TenantContext::run(
            $this->tenantId,
            fn () => WallFixture::query()->count()
        );
    }
}
