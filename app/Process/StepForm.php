<?php

namespace App\Process;

use App\Exceptions\ProcessRefused;
use App\Models\Designation;
use App\Models\FormField;
use App\Models\OrgUnit;
use App\Models\ProcessStep;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The questions a step asks, and the rules those questions are checked against.
 *
 * One place turns a client's rows into Laravel validation rules, and it is the only place
 * allowed to. The rules are applied **on the server, always** — what the browser does is
 * a convenience and never the check, because the browser is the half of this an employee
 * controls.
 *
 * **Nothing a client types ever reaches Laravel's rule parser as text.** A rule string is
 * a small language: `exists:users`, `regex:...`, and a client-editable row carrying one
 * would let whoever edits the finance clearance form decide which rules run on it. So a
 * question's limits are named numbers — `min`, `max`, `max_length` — read by the `match`
 * below and turned into rules here. The question's own key is held to a plain identifier
 * by the database, which is what keeps it out of Laravel's dot notation as well.
 */
class StepForm
{
    /**
     * How long a one-line answer may be before it is refused, and how long a paragraph
     * may be. Both overridable per question with `max_length`, both here so that a client
     * who sets nothing still cannot post a novel into a jsonb column.
     */
    public const DefaultTextLength = 255;

    public const DefaultParagraphLength = 5000;

    /**
     * How large a document may be, in kilobytes, and which kinds are accepted.
     *
     * Twenty megabytes is BambooHR's own published limit and is comfortably more than a
     * scanned clearance note needs. The list is what a clearance is actually evidenced
     * with — a scan, a photograph, or the letter somebody typed.
     *
     * **Read here and nowhere else.** Livewire refuses an upload against its own rule
     * before ours is ever reached, so the same cap and the same list are handed to it
     * when the application boots. Written twice, the two would drift and the person would
     * be refused by a limit nothing in the product admits to.
     */
    public const DocumentKilobytes = 20480;

    /** @var list<string> */
    public const DocumentTypes = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'];

    /**
     * What this step asks, in the order the client put the questions in.
     *
     * A step with no form asks nothing, which is the default and is right for a manager
     * sign-off that is only a decision.
     *
     * @return Collection<int, FormField>
     */
    public function fields(ProcessStep $step): Collection
    {
        if ($step->form_definition_id === null) {
            return collect();
        }

        return FormField::query()
            ->where('form_definition_id', $step->form_definition_id)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The questions this step is actually asking, given what has been answered so far.
     *
     * A question can be hidden by an earlier answer on the same form: finance is not
     * asked what it is recovering until it says the imprest card did not come back. The
     * condition is the same flat one-comparison shape a step's own opening conditions
     * use, and it reads only the answers on this form — not the case's other steps, not
     * the person's details, not a client setting. Those are a step's business, and a
     * question that needed them would be a step of its own.
     *
     * **Hidden is decided here and nowhere else.** ServiceNow spent years hiding fields
     * in the browser while the server went on demanding them, which is a step nobody can
     * complete: the box is not on the screen and the refusal says it is required. So the
     * rules below, the answers kept, and the questions the screen draws all come through
     * this one method.
     *
     * Read in the client's own order, and each question can only be hidden by one above
     * it — the rule publishing enforces. That is what makes one pass enough: by the time
     * a question is reached, every answer its condition can name has already been decided
     * visible or dropped, so hiding cascades on its own. Finance says the card came back,
     * the recovery amount goes, and the reason for the recovery goes with it.
     *
     * @param  array<string, mixed>  $answers
     * @return Collection<int, FormField>
     */
    public function asking(ProcessStep $step, array $answers): Collection
    {
        $given = $this->answered($answers);

        // Only the answers to questions already decided visible, which is what makes a
        // hidden question's answer unable to decide anything below it.
        $thatCount = [];
        $asking = [];

        foreach ($this->fields($step) as $field) {
            if (! $this->shown($field, $thatCount)) {
                continue;
            }

            $asking[] = $field;

            if (array_key_exists($field->key, $given)) {
                $thatCount[$field->key] = $given[$field->key];
            }
        }

        return collect($asking);
    }

    /**
     * Whether one question is asked, given the answers above it that count.
     *
     * A list of sets, asked at all when any one set is fully true — the same shape and
     * the same reading as a step's opening conditions. Nothing written means always.
     *
     * @param  array<string, mixed>  $thatCount
     */
    private function shown(FormField $field, array $thatCount): bool
    {
        // Cast, because this is a column a client's own editor writes and anything that is
        // not a list of sets would otherwise be walked as one.
        $sets = (array) ($field->visible_if ?? []);

        if ($sets === []) {
            return true;
        }

        foreach ($sets as $set) {
            $holds = true;

            foreach ((array) $set as $condition) {
                $named = ((array) $condition)['field'] ?? null;

                $holds = $holds && Comparison::holds(
                    is_string($named) ? ($thatCount[$named] ?? null) : null,
                    ((array) $condition)['operator'] ?? null,
                    ((array) $condition)['value'] ?? null,
                );
            }

            if ($holds) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Laravel rules for one step's answers, keyed by question.
     *
     * Only the questions actually being asked. A required question that is hidden is not
     * required — it is not asked — and demanding it would leave the step impossible to
     * complete with nothing on the screen saying why.
     *
     * **`$finishingTheStep` decides whether a required question is demanded**, and only
     * that. Every limit the client wrote on a question — how long, how large, which of
     * their own rows, what kind of file — holds either way, because a rejection carrying
     * a twenty-megabyte file or a negative recovery figure is wrong however the step
     * ended. See {@see onlyWhatThisStepAsks()} for why the two are separated at all.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function rules(ProcessStep $step, array $answers, bool $finishingTheStep = true): array
    {
        $rules = [];

        foreach ($this->asking($step, $answers) as $field) {
            $rules[$field->key] = [
                ...($finishingTheStep ? $this->presence($field) : ['nullable']),
                ...$this->forType($field),
            ];

            if ($field->type === FormField::Multiselect) {
                // Each chosen value, not the list. Written separately because Laravel
                // checks the members of an array under their own key.
                $rules[$field->key.'.*'] = [Rule::in($field->choices())];
            }
        }

        return $rules;
    }

    /**
     * The rows a picker offers, as `id => name`.
     *
     * Only the three picker questions have any, and each reads the client's own table.
     * The tenant wall does the scoping, so this is the client's own people, their own
     * departments and their own designations without a word here saying so.
     *
     * Here rather than on the queue screen, because two things need it: the screen
     * drawing the list to choose from, and the panel above an approver's form turning
     * the number that was chosen back into words.
     *
     * @return array<int, string>
     */
    public function optionsFor(FormField $field): array
    {
        return match ($field->type) {
            FormField::UserPicker => User::query()->orderBy('name')->pluck('name', 'id')->all(),
            FormField::OrgUnitPicker => OrgUnit::query()->orderBy('name')->pluck('name', 'id')->all(),
            FormField::DesignationPicker => Designation::query()->where('active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /**
     * One picker answer as the row's own name, whether or not it is still offered.
     *
     * Not read off the list above, which is the list of choices a person may still make:
     * a designation the client has retired is rightly gone from it, and an approval
     * already asking for that designation must still say so rather than reading back as
     * nothing. So the row is read, and it is also the smaller read of the two.
     */
    public function nameOfThePicked(FormField $field, mixed $id): ?string
    {
        return match ($field->type) {
            FormField::UserPicker => User::query()->find($id)?->name,
            FormField::OrgUnitPicker => OrgUnit::query()->find($id)?->name,
            FormField::DesignationPicker => Designation::query()->find($id)?->name,
            default => null,
        };
    }

    /**
     * The client's own words for each question, so a refusal reads "Imprest card
     * returned is required" rather than naming the column it is stored under.
     *
     * @return array<string, string>
     */
    public function labels(ProcessStep $step): array
    {
        return $this->fields($step)
            ->mapWithKeys(fn (FormField $field): array => [$field->key => $field->label])
            ->all();
    }

    /**
     * Refuse an answer to a question this step does not ask, and keep only what was
     * actually answered.
     *
     * This is not tidiness. Every live step's answers are read together when the engine
     * works out which steps a case still wants, so without this the person holding IT's
     * clearance can post `settlement_cleared` and switch off the finance clearance behind
     * it — a step that never happens and an exit that closes as though it did.
     *
     * It sits on the engine rather than on a screen because there are three ways an
     * answer arrives: the queue screen, a link sent to somebody with no account, and a
     * console command. A guard on one of them is a guard on none.
     *
     * An empty box is dropped rather than stored: see {@see answered()}.
     *
     * **A question the form does not have is refused; a question it has but is not asking
     * is dropped.** The difference is not a detail. Chandni types a recovery amount, then
     * says the imprest card came back after all, and the box she typed in is no longer on
     * the screen — the figure has to go, and refusing it would be a refusal she has
     * nothing to do about. An answer to a question no version of the form ever asked is
     * somebody reaching past the screen, and that is refused.
     *
     * **And the client's own rules are applied here, which is the whole point of putting
     * this on the engine.** Until this existed the only thing demanding a required
     * question was the queue screen, so Neha Deshpande answering the same clearance
     * through the link sent to her filed it with every box empty and the exit closed as
     * though it were cleared. Every product looked at has this hole somewhere — Jira's
     * approval steps skip its own validators, Camunda's form rules are not applied when
     * a task is completed through its API, and ServiceNow's depend on which screen was
     * used — and each of them has it because the check lives with a screen rather than
     * with the write.
     *
     * `$finishingTheStep` is false for a rejection, a hold and a send-back. None of the
     * three is the step's answers being filed; each is a reason it is not finished, and
     * demanding a complete form on any of them makes that outcome unreachable in exactly
     * the case it exists for — an approver rejects *because* an answer is wrong, and a
     * send-back exists to get one corrected. ServiceNow lands in the same place from the
     * other direction: what it makes compulsory on a rejection is the comment, not the
     * form. Ours is the reason on the case, which the engine already demands for a hold.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function onlyWhatThisStepAsks(ProcessStep $step, array $payload, bool $finishingTheStep = true): array
    {
        if ($payload === [] && ! $finishingTheStep) {
            return [];
        }

        $unasked = array_values(array_diff(
            array_keys($payload),
            $this->fields($step)->pluck('key')->all(),
        ));

        if ($unasked !== []) {
            throw ProcessRefused::thatStepDoesNotAskThat($step->name, $unasked);
        }

        $given = $this->answered($payload);
        $asking = $this->asking($step, $given);

        // Hidden here as well as on the screen and in the rules, in the one pass. A
        // question hidden on the screen and still stored is how a figure nobody was
        // asked for ends up deciding a later step.
        $kept = array_intersect_key($given, array_flip($asking->pluck('key')->all()));

        // Shape first, because it is the one refusal that says what the question takes
        // rather than what is wrong with the answer, and because it is what lets the
        // write below trust that a document question is holding a real upload.
        $this->refuseUnlessEachAnswerIsTheRightShape($step, $asking, $kept);

        // Then the client's own rules, and still before a single file has moved: what a
        // person attached is the file itself at this point, so its size and its kind can
        // be checked. A moment later it is a note of where the file went, which no rule
        // about files can read.
        $this->refuseUnlessTheAnswersFit($step, $kept, $finishingTheStep);

        return $this->withDocumentsPutAway($step, $asking, $kept);
    }

    /**
     * Hold the answers to the client's own rules, and name every problem at once.
     *
     * `ponytail:` this checks what is being written now, not what is already on a step
     * that was held and is being approved later — the engine merges the two and only the
     * new half comes through here. The queue screen clears its boxes when a step is held
     * and does not fill them back in, so today a held step is answered again in full
     * anyway. Two things have to come in here together when a screen starts showing a held
     * step's earlier answers back: the already-stored half, and the hiding — Chandni
     * holding finance with a recovery figure and then approving it saying the card came
     * back leaves that figure on the row, where a later step's condition can still read
     * it. Module 05's step 6 is where the hold screen arrives and owns both.
     *
     * @param  array<string, mixed>  $kept
     */
    private function refuseUnlessTheAnswersFit(ProcessStep $step, array $kept, bool $finishingTheStep): void
    {
        $validator = Validator::make(
            $kept,
            $this->rules($step, $kept, $finishingTheStep),
            [],
            $this->labels($step),
        );

        if ($validator->fails()) {
            throw ProcessRefused::cannotBeRecordedWithThoseAnswers($step->name, $validator->errors()->all());
        }
    }

    /**
     * **A document question takes an attached file and nothing else, and no other
     * question takes one.** That is the guard, and it matters more than it looks. What is
     * held while somebody fills a card in is a real upload, put there by Livewire's own
     * signed endpoint; what arrives as a bare list of words is somebody writing the
     * answer by hand, and a hand-written `{disk, path}` would let a person name any file
     * on the disk as their clearance evidence. The other way round is the same hole from
     * the other side — an upload aimed at the remarks box, which nothing would then check
     * the size or the kind of.
     *
     * Every answer is checked before any file is written, so a card refused over one
     * question does not leave the file from another one lying on the disk.
     *
     * @param  Collection<int, FormField>  $asking
     * @param  array<string, mixed>  $kept
     */
    private function refuseUnlessEachAnswerIsTheRightShape(ProcessStep $step, Collection $asking, array $kept): void
    {
        $questions = $asking->keyBy('key');

        $wrong = [];

        foreach ($kept as $key => $answer) {
            if (($questions[$key]->type === FormField::File) !== ($answer instanceof UploadedFile)) {
                $wrong[] = '['.$questions[$key]->label.']';
            }
        }

        if ($wrong !== []) {
            throw ProcessRefused::thatIsNotWhatTheQuestionTakes($step->name, $wrong);
        }
    }

    /**
     * Move each attached document out of the browser's holding area and record where it
     * went, in place of the file itself.
     *
     * Reached only once {@see refuseUnlessEachAnswerIsTheRightShape()} has held, so every
     * answer on a document question here is a real upload.
     *
     * What is stored is where it went, what the person called it, how big it was and what
     * it actually is. The name is kept for reading back only and is never part of the
     * path: the path is a random name Laravel derives from the file's real kind, so
     * nothing a person types decides where their file lands or what it is called there.
     *
     * The write happens inside the same transaction the answer is recorded in. If that
     * transaction rolls back the file stays on the disk with no row pointing at it, which
     * is the right way round — a row pointing at a file that is not there is the failure
     * worth avoiding, and nothing here is ever deleted automatically anyway.
     *
     * @param  Collection<int, FormField>  $asking
     * @param  array<string, mixed>  $kept
     * @return array<string, mixed>
     */
    private function withDocumentsPutAway(ProcessStep $step, Collection $asking, array $kept): array
    {
        $questions = $asking->keyBy('key');

        foreach ($kept as $key => $answer) {
            if ($answer instanceof UploadedFile) {
                $kept[$key] = $this->putAway($step, $answer, $questions[$key]->label);
            }
        }

        return $kept;
    }

    /**
     * @return array{disk: string, path: string, name: string, size: int, type: string}
     */
    private function putAway(ProcessStep $step, UploadedFile $document, string $label): array
    {
        $disk = (string) config('startx.documents_disk');

        // A random name with the extension of whatever the file really is, under the
        // client company's own folder. Laravel writes both; nothing here comes from the
        // browser.
        $path = $document->storeAs(
            'case-documents/'.$step->tenant_id,
            $document->hashName(),
            ['disk' => $disk],
        );

        // A failed write is not always complained about. Ordinary storage reports one by
        // handing back nothing; the browser's own holding area hands back the path either
        // way and throws away whether the write worked at all. So what is checked is the
        // file being there, which is the only answer that means anything: taken on trust,
        // this records a clearance approved against evidence nobody can open.
        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
            throw ProcessRefused::thatDocumentWasNotSaved($step->name, $label);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'name' => mb_substr((string) $document->getClientOriginalName(), 0, self::DefaultTextLength),
            'size' => (int) $document->getSize(),
            'type' => (string) $document->getMimeType(),
        ];
    }

    /**
     * What was actually answered.
     *
     * A box left empty is not an answer, and filing it as one is not harmless. A later
     * step that opens when the recovery amount was answered at all reads an empty box as
     * an answer, so the director's approval appears on an exit where nobody entered a
     * figure — and a threshold compared against an empty box is quietly false on every
     * case, which is the same failure the other way round. Not answered is recorded by
     * the answer not being there, and it is what decides whether the question below it is
     * asked.
     *
     * Read by the queue screen as well as from here, so that the screen checks what the
     * engine will check. A yes/no box left on "Not answered" arrives as an empty string:
     * the screen used to hand that to Laravel untouched, which lets a required question
     * pass, and the engine dropped it and refused — so the person got a refusal at the top
     * of the page instead of a message under the box they had to fill in.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function answered(array $payload): array
    {
        return array_filter(
            $payload,
            fn (mixed $answer): bool => $answer !== null && $answer !== '' && $answer !== [],
        );
    }

    /**
     * Whether an answer has to be there at all.
     *
     * A required yes/no question uses `present` rather than `required`, because "no" is a
     * real answer and Laravel counts a false as nothing at all. Asking whether the
     * mailbox was switched off and getting an explicit no is the whole point of asking.
     *
     * @return list<string>
     */
    private function presence(FormField $field): array
    {
        if (! $field->required) {
            return ['nullable'];
        }

        return $field->type === FormField::Boolean ? ['present'] : ['required'];
    }

    /**
     * One arm per question type, and nothing clever behind it. Twelve `match` arms are
     * the whole of what a type-class hierarchy would have been.
     *
     * The three pickers lean on `exists` against the client's own tables, and the tenant
     * wall does the rest: row-level security is on those tables, so a picker cannot be
     * made to point at another company's row by editing the number in the browser. That
     * is the check, not an afterthought — it is why these are pickers and not free text.
     *
     * @return list<mixed>
     */
    private function forType(FormField $field): array
    {
        $limits = (array) $field->validation;

        return match ($field->type) {
            FormField::Text => ['string', 'max:'.$this->length($limits, self::DefaultTextLength)],
            FormField::Textarea => ['string', 'max:'.$this->length($limits, self::DefaultParagraphLength)],
            FormField::Number => ['numeric', ...$this->range($limits)],
            // Never negative. A recovery of minus two thousand rupees is a payable, and
            // the settlement statement has a separate line for that.
            FormField::Money => ['numeric', 'min:0', ...$this->range($limits)],
            // The date the browser's own date box sends, and nothing looser. `date` on
            // its own accepts "tomorrow", which is a date today and a different one next
            // week — and these are read off a closed case years later.
            FormField::Date => ['date_format:Y-m-d'],
            FormField::Select => ['string', Rule::in($field->choices())],
            FormField::Multiselect => ['array'],
            FormField::Boolean => ['boolean'],
            FormField::UserPicker => ['integer', Rule::exists('users', 'id')],
            FormField::OrgUnitPicker => ['integer', Rule::exists('org_units', 'id')],
            FormField::DesignationPicker => ['integer', Rule::exists('designations', 'id')],
            // The kind is read off the file's own contents, never off its name.
            // Laravel's `mimes` rule looks at what the file is and asks which extension
            // that kind uses; `extensions` would read the name the browser sent, which
            // is a string the person choosing the file writes.
            FormField::File => [
                'file',
                'max:'.self::DocumentKilobytes,
                'mimes:'.implode(',', self::DocumentTypes),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function length(array $limits, int $default): int
    {
        $asked = $limits['max_length'] ?? null;

        return is_numeric($asked) && (int) $asked > 0 ? (int) $asked : $default;
    }

    /**
     * A client's own floor and ceiling on a number, as numbers. Anything that is not a
     * number is ignored rather than passed through.
     *
     * @param  array<string, mixed>  $limits
     * @return list<string>
     */
    private function range(array $limits): array
    {
        $rules = [];

        if (is_numeric($limits['min'] ?? null)) {
            $rules[] = 'min:'.(float) $limits['min'];
        }

        if (is_numeric($limits['max'] ?? null)) {
            $rules[] = 'max:'.(float) $limits['max'];
        }

        return $rules;
    }
}
