<?php

namespace Tests\Fixtures;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-owned model standing in for the real ones, so the tenant wall can be
 * tested before `org_units`, `users` and the rest exist. Its table is created per
 * test by `createWalledFixtureTables()` in `tests/Pest.php`.
 */
class WallFixture extends Model
{
    use BelongsToTenant;

    protected $table = 'wall_fixtures';

    public $timestamps = false;

    // Deliberately without `tenant_id`: the trait stamps it from the client company
    // in scope, and leaving it mass-assignable would let a form choose one.
    protected $fillable = ['name', 'parent_id'];
}
