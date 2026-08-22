<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Process\PublishCheck;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ProcessTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
/*
 * `version` and `status` are deliberately absent from what a form may fill. Both are
 * the engine's own answers — the version comes from counting what already exists, and
 * the status changes only by going live or being retired, each of which is an act with
 * checks behind it. The same reason a case's closing date is not fillable either.
 */
#[Fillable(['key', 'name', 'subject_kind'])]
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
    public const Draft = 'draft';

    public const Published = 'published';

    public const Archived = 'archived';

    public const Statuses = [self::Draft, self::Published, self::Archived];

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

    /**
     * Make this version live: check it is fit to run, retire the version it replaces,
     * and freeze its steps for good.
     *
     * The freeze itself is not done here — the database refuses a change to a live
     * version's steps, because the whole protection a running case has is that the
     * version it points at cannot change, and a rule only this method applies is one an
     * import or a console command walks straight round.
     *
     * The order inside the transaction matters, the same way it does when a job row is
     * withdrawn. One process has one live version, enforced by the database, so the
     * previous one is retired before this one is made live rather than after.
     */
    public function publish(): void
    {
        if ($this->status !== self::Draft) {
            throw ProcessRefused::onlyADraftGoesLive($this->name, $this->version, $this->status);
        }

        $problems = (new PublishCheck($this))->problems();

        if ($problems !== []) {
            throw ProcessRefused::cannotPublish($this->name, $this->version, $problems);
        }

        DB::transaction(function (): void {
            static::query()
                ->where('key', $this->key)
                ->where('status', self::Published)
                ->whereKeyNot($this->getKey())
                ->update(['status' => self::Archived]);

            $this->status = self::Published;
            $this->save();
        });
    }

    /**
     * Start the next version of this process as a draft, with this version's steps
     * copied into it and this version left exactly as it is.
     *
     * This is what editing a live process means here. Anjali changes the approver on
     * Meridian's exit and gets a fresh set of rows to work on; Rakesh's exit, already
     * running, carries on reading the version it opened on and notices nothing. Nothing
     * moves a running case onto the new version, which is a decision rather than an
     * omission — the reasoning is in this module's plan.
     */
    public function draftNextVersion(): self
    {
        if ($this->status === self::Draft) {
            throw ProcessRefused::aDraftIsEditedInPlace($this->name, $this->version);
        }

        // One unfinished draft at a time. Two clicks on the edit button used to make two
        // copies of the live version, and then whichever was made live second won:
        // Anjali fixes the approver on the first and switches it on, somebody switches
        // the second one on a week later, and her fix is silently back to what it was.
        $unfinished = static::query()->where('key', $this->key)->where('status', self::Draft)->first();

        if ($unfinished !== null) {
            throw ProcessRefused::anUnfinishedDraftAlreadyExists($this->name, $unfinished->version);
        }

        return DB::transaction(function (): self {
            // Copied rather than listed column by column, the same way the steps below
            // are, so a column added to this table later carries into the next version
            // instead of quietly not.
            $draft = $this->replicate(['tenant_id']);
            $draft->version = (int) static::query()->where('key', $this->key)->max('version') + 1;
            $draft->status = self::Draft;
            $draft->save();

            foreach ($this->steps as $step) {
                // The tenant is left off so it is stamped from the client company in
                // scope, rather than carried across by a copy nobody checked.
                $copy = $step->replicate(['tenant_id', 'template_id']);
                $copy->template_id = $draft->getKey();
                $copy->save();
            }

            return $draft;
        });
    }
}
