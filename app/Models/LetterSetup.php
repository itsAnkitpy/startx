<?php

namespace App\Models;

use App\Exceptions\ProcessRefused;
use App\Process\Letter;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * What one client company prints its letters with: the wording of each letter kind, and the
 * letterhead, footer strip and signature around it.
 *
 * One row per company, made the first time somebody saves the letters screen. Until then the
 * wording is our starter wording, read from {@see Letter}. That save keeps both letters as
 * the screen showed them, starter wording included, so from then on each is the company's
 * own and a change to ours does not reach it.
 *
 * The three pictures are paths on the documents disk, written by the screen's own upload,
 * which checks each one is a PNG or JPEG that draws and refuses a path the browser sends back
 * that this row does not already hold.
 */
#[Fillable(['relieving_letter', 'experience_letter', 'letterhead', 'footer', 'signature', 'signed_by', 'signed_as'])]
class LetterSetup extends Model
{
    use BelongsToTenant;

    /** The company in scope's own, or an unsaved one holding nothing. */
    public static function current(): self
    {
        return self::query()->firstOrNew();
    }

    public function wordingOf(string $kind): string
    {
        $starter = Letter::starterWording($kind);

        return $this->getAttribute($kind) ?? $starter;
    }

    /**
     * What goes around the wording on the page, as saved.
     *
     * @return array{letterhead: string|null, footer: string|null, signature: string|null, signed_by: string, signed_as: string}
     */
    public function around(): array
    {
        return [
            'letterhead' => $this->picture('letterhead'),
            'footer' => $this->picture('footer'),
            'signature' => $this->picture('signature'),
            'signed_by' => (string) $this->signed_by,
            'signed_as' => (string) $this->signed_as,
        ];
    }

    /**
     * A saved picture's bytes, or nothing when none is saved.
     *
     * A saved picture whose file cannot be read is refused rather than read as none: the
     * disk answers a missing file with nothing, and a letter would go out without it. Refused
     * in words for HR, since issuing an exit's letters is where it is met.
     *
     * ponytail: read from today's documents disk, which is where the screen writes them.
     * Copy them across, or keep the disk beside each path, when that disk changes.
     */
    public function picture(string $which): ?string
    {
        $path = match ($which) {
            'letterhead' => $this->letterhead,
            'footer' => $this->footer,
            'signature' => $this->signature,
        };

        if ($path === null) {
            return null;
        }

        return Storage::disk((string) config('startx.documents_disk'))->get($path)
            ?? throw ProcessRefused::aLetterPictureCannotBeRead($which);
    }
}
