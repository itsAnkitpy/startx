{{--
    What is waiting on the person signed in.

    Every line here is worked out on load rather than read from a table, which is the
    whole claim of the module behind it. A step nobody has picked up has no row anywhere
    and still appears; a step somebody else took has a row and does not.
--}}
<x-filament-panels::page>
    @php($queue = $this->queue())
    @php($heldByNobody = $this->heldByNobody($queue))
    @php($byEscalation = $this->cameByEscalation($queue))
    @php($alreadySaid = $this->whatWasSaidAbout($queue))
    @php($coveringFor = $this->coveringSomebodyOn($queue))

    @if ($queue->isEmpty())
        <x-filament::section>
            <div class="py-8 text-center">
                <p class="text-base font-medium text-gray-950 dark:text-white">Nothing is waiting on you.</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    When a step of a live case becomes yours, it appears here on its own — nobody has to send it.
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-4">
            @foreach ($queue as $waiting)
                @php($case = $waiting->case)
                @php($step = $waiting->step)
                @php($mine = $waiting->attempt?->assignee_id === auth()->id())
                @php($nobodyHolds = in_array($case->getKey().':'.$step->sequence, $heldByNobody, true))
                @php($escalated = in_array($case->getKey().':'.$step->sequence, $byEscalation, true))
                @php($awayPerson = $coveringFor[$case->getKey().':'.$step->sequence] ?? null)
                @php($onHold = $waiting->attempt?->outcome === 'held')
                @php($whatWasSaid = $alreadySaid[$case->getKey().':'.$step->sequence] ?? null)

                <x-filament::section>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        {{-- Spaced with a plain style: Filament 4 ships only its own
                             classes compiled and this project has no front-end build, so
                             the utility classes on this page reach the browser as nothing
                             at all and everything inside a card renders flush. --}}
                        <div style="display: grid; row-gap: 0.5rem; min-width: 0;">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-semibold text-gray-950 dark:text-white">
                                    {{ $step->name }}
                                </span>

                                @if ($waiting->escalationOwed)
                                    <x-filament::badge color="danger">Past its deadline</x-filament::badge>
                                @elseif ($waiting->nudgesOwed > 0)
                                    <x-filament::badge color="warning">Due soon</x-filament::badge>
                                @endif

                                {{-- A held step is still open and still this person's, so
                                     without this it reads as a clearance nobody has got
                                     round to. The badge is the state; the buttons below stay
                                     as they are, because ending a hold is answering the step
                                     properly. --}}
                                @if ($onHold)
                                    <x-filament::badge color="warning">On hold</x-filament::badge>
                                @elseif ($mine)
                                    <x-filament::badge color="info">You picked this up</x-filament::badge>
                                @endif
                            </div>

                            {{-- What has already been said about this step: why it came
                                 back, or why it is on hold. Saying it here rather than only
                                 in the case's history is the whole reason somebody is made
                                 to type it. --}}
                            @if ($whatWasSaid)
                                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                                    {{ $whatWasSaid }}
                                </p>
                            @endif

                            {{-- Escalation adds people and removes nobody, so this card has
                                 to say it arrived rather than let it read as a job that
                                 has been moved onto this person. --}}
                            @if ($escalated)
                                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                                    This came to you because it is past its deadline. It is still open to
                                    the people it belonged to, and it stays theirs — you have been added,
                                    not handed it.
                                </p>
                            @endif

                            {{-- Marked for the same reason as the two above: a card that
                                 arrived because you are standing in for a colleague looks
                                 exactly like your own work, and this is the one of the
                                 three where somebody else's decision is being made. --}}
                            @if ($awayPerson)
                                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                                    This is {{ $awayPerson }}'s to decide. You have it because you are
                                    covering for {{ $awayPerson }} while they are away, and whatever you
                                    answer is recorded in both names. It stops reaching you when the cover
                                    ends.
                                </p>
                            @endif

                            {{-- The whole reason this is marked: a step that reached this
                                 list because the company named a stand-in looks exactly
                                 like one that is genuinely this person's job, and
                                 approving the second believing it was the first is the
                                 failure the warning exists to prevent. --}}
                            @if ($nobodyHolds)
                                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                                    Nobody holds the role this step asked for, so it came to you as the
                                    company's stand-in. You can act on it, and appointing somebody to the
                                    role is the real fix.
                                </p>
                            @endif

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $case->template->name }}
                                @if ($case->subject)
                                    — about {{ $case->subject->name }}
                                @endif
                            </p>

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Became yours {{ $waiting->availableSince->diffForHumans() }}@if ($waiting->dueAt),
                                    due {{ $waiting->dueAt->diffForHumans() }}@endif.
                            </p>

                            {{-- Who it is about, before what it asks. Somebody clearing an
                                 exit under a two-day clock cannot decide anything without
                                 the person's details in front of them, and they will not
                                 go and look them up in another screen. --}}
                            @include('filament.pages.partials.subject-panel', ['case' => $case])

                            {{-- What this step asks. A step with no form asks nothing and
                                 shows nothing, which is right for a sign-off that is only
                                 a decision. --}}
                            @php($questions = $this->questionsOn($waiting))

                            @if ($questions->isNotEmpty())
                                {{-- Spaced with a plain style rather than utility classes:
                                     this project has no front-end build, so only the
                                     classes Filament ships compiled reach the browser. --}}
                                <x-filament::fieldset label="What this step asks">
                                    <div style="display: grid; row-gap: 1rem;">
                                        @foreach ($questions as $field)
                                            @include('filament.pages.partials.step-question', [
                                                'field' => $field,
                                                'under' => "answers.{$case->getKey()}.{$step->sequence}",
                                            ])
                                        @endforeach
                                    </div>
                                </x-filament::fieldset>
                            @endif

                            {{-- And what earlier steps already attached, so a clearance is
                                 verified rather than taken on trust. Whoever holds a step
                                 of a case may open its documents. --}}
                            @include('filament.pages.partials.case-documents', [
                                'case' => $case,
                                'documents' => $this->documentsOn($waiting),
                            ])
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @unless ($mine)
                                {{-- Picking it up is what stops two people in a shared queue
                                     both working on the same thing. --}}
                                <x-filament::button
                                    color="gray"
                                    wire:click="pickUp({{ $case->getKey() }}, {{ $step->sequence }})"
                                    wire:loading.attr="disabled"
                                >
                                    Pick it up
                                </x-filament::button>
                            @endunless

                            {{-- Approving is one press. Every other outcome asks for a reason
                                 first: the engine refuses a hold and a send-back without one,
                                 and a rejection with no words on it tells the person whose
                                 request ended nothing at all. --}}
                            @foreach ($step->allowed_outcomes ?? [] as $outcome)
                                {{-- Nothing offers a step the state it is already in. A
                                     clearance on hold needs answering, not holding again,
                                     and the reason it was held is on the card above. --}}
                                @continue ($onHold && $outcome === 'held')

                                <x-filament::button
                                    :color="$outcome === 'approved' ? 'success' : ($outcome === 'rejected' ? 'danger' : 'gray')"
                                    wire:click="{{ $outcome === 'approved' ? 'decide' : 'askFor' }}({{ $case->getKey() }}, {{ $step->sequence }}, '{{ $outcome }}')"
                                    wire:loading.attr="disabled"
                                >
                                    {{ $this->buttonFor($outcome) }}
                                </x-filament::button>
                            @endforeach
                        </div>
                    </div>

                    {{-- The reason, asked between the button and the decision rather than
                         beside a form nobody is filling in. Only one card asks at a time. --}}
                    @php($asked = $case->getKey().':'.$step->sequence)

                    @if (str_starts_with((string) $this->asking, $asked.':'))
                        @php($outcome = explode(':', $this->asking)[2])
                        @php($reason = "reasons.{$case->getKey()}.{$step->sequence}")
                        @php($goesBackTo = $outcome === 'sent_back' ? $this->whereItCanGoBackTo($waiting) : collect())

                        <div style="display: grid; row-gap: 1rem; margin-top: 1rem;">
                            {{-- Asked only where there is genuinely a choice. From the branch
                                 approval there is one place to go and the card does not make
                                 anybody read a list of one. --}}
                            @if ($goesBackTo->count() > 1)
                                <x-filament-forms::field-wrapper
                                    :id="'back-'.$asked"
                                    label="Which step it goes back to"
                                    required
                                    :state-path="'sendBackTo.'.$case->getKey().'.'.$step->sequence"
                                >
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select
                                            :id="'back-'.$asked"
                                            wire:model.live="sendBackTo.{{ $case->getKey() }}.{{ $step->sequence }}"
                                        >
                                            @foreach ($goesBackTo as $earlier)
                                                <option value="{{ $earlier->sequence }}">{{ $earlier->name }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </x-filament-forms::field-wrapper>
                            @endif

                            <x-filament-forms::field-wrapper
                                :id="'reason-'.$asked"
                                :label="$this->asksFor($outcome)"
                                :required="$outcome !== 'rejected'"
                                :state-path="$reason"
                            >
                                {{-- `fi-fo-textarea` on the wrapper and no class on the box
                                     itself, for the reason the question partial gives: Filament
                                     styles a one-line box with an element selector, so the same
                                     class on a textarea matches nothing. --}}
                                <x-filament::input.wrapper :valid="! $errors->has($reason)" class="fi-fo-textarea">
                                    <textarea
                                        id="reason-{{ $asked }}"
                                        rows="3"
                                        wire:model.live.blur="{{ $reason }}"
                                    ></textarea>
                                </x-filament::input.wrapper>
                            </x-filament-forms::field-wrapper>

                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                <x-filament::button
                                    :color="$outcome === 'rejected' ? 'danger' : 'gray'"
                                    wire:click="decide({{ $case->getKey() }}, {{ $step->sequence }}, '{{ $outcome }}')"
                                    wire:loading.attr="disabled"
                                >
                                    {{ $this->buttonFor($outcome) }}
                                </x-filament::button>

                                <x-filament::button color="gray" wire:click="stopAsking">
                                    Cancel
                                </x-filament::button>
                            </div>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
