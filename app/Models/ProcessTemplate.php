<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\ProcessTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one client's process — their exit, their onboarding, their hiring
 * request.
 *
 * A version is a row, not a number on a row. Editing a published process writes the
 * next version as a fresh set of rows and leaves the old ones untouched, so a case that
 * opened last week can simply point at the row it opened on and read the process from
 * there for as long as it runs. Nothing is copied onto the case and nothing can drift,
 * because the thing the case points at cannot change.
 *
 * `key` is what stays the same across versions; `name` is the client's own words and is
 * theirs to rename at any time.
 */
#[Fillable(['key', 'name', 'version', 'status', 'subject_kind'])]
class ProcessTemplate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProcessTemplateFactory> */
    use HasFactory;

    /**
     * A draft can be edited and cannot be run. A published version can be run and cannot
     * be edited. An archived one can do neither and stays readable, because cases that
     * ran on it are still being read.
     */
    public const Statuses = ['draft', 'published', 'archived'];

    /**
     * What the process is about. An exit is about an employee, so the case cannot open
     * without one. An onboarding is about a candidate who has no account until that
     * process's own last step creates one. A hiring request is about a vacant position
     * and about nobody at all.
     */
    public const SubjectKinds = ['employee', 'candidate', 'none'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProcessStep::class, 'template_id')->orderBy('sequence');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ProcessCase::class, 'template_id');
    }
}
