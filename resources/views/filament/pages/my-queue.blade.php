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

                                @if ($mine)
                                    <x-filament::badge color="info">You picked this up</x-filament::badge>
                                @endif
                            </div>

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
                                                'caseId' => $case->getKey(),
                                                'sequence' => $step->sequence,
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

                            @foreach ($step->allowed_outcomes ?? [] as $outcome)
                                <x-filament::button
                                    :color="$outcome === 'approved' ? 'success' : ($outcome === 'rejected' ? 'danger' : 'gray')"
                                    wire:click="decide({{ $case->getKey() }}, {{ $step->sequence }}, '{{ $outcome }}')"
                                    wire:loading.attr="disabled"
                                >
                                    {{ ucfirst(str_replace('_', ' ', $outcome)) }}
                                </x-filament::button>
                            @endforeach
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
