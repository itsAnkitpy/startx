{{--
    One thing waiting on the person signed in, and the buttons that answer it.

    A row of the queue's table, and the whole card is the row: the questions the client
    wrote, the person's details above them and the documents below all sit here, so an
    approval is given without opening anything.

    Everything the card says about itself was worked out once for the whole list and
    handed over on the row — the three marks each cost a query or a walk through the rule
    that says who a step belongs to, so a card working out its own would multiply that by
    however many are on screen.
--}}
@php
    $page = $getLivewire();
    $waiting = $record['waiting'];
    $case = $waiting->case;
    $step = $waiting->step;
    $mine = $waiting->attempt?->assignee_id === auth()->id();
    $onHold = $waiting->attempt?->outcome === 'held';
    $nobodyHolds = $record['nobodyHolds'];
    $escalated = $record['escalated'];
    $whatWasSaid = $record['whatWasSaid'];
    $awayPerson = $record['coveringFor'];
@endphp

{{-- No section around this. In a content grid Filament already draws each row as a
     card — rounded, ringed and on white — so a section here would be a card inside a
     card. The side padding is a plain style because the theme only compiles the utility
     classes something in this project already uses, and nothing used these. --}}
<div style="padding-inline: 1.5rem; width: 100%;">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        {{-- Spaced with a plain style: Filament ships only its own classes compiled and
             this project's theme carries no utility build, so the utility classes on this
             card reach the browser as nothing at all and everything inside it renders
             flush. --}}
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

                {{-- A held step is still open and still this person's, so without this it
                     reads as a clearance nobody has got round to. The badge is the state;
                     the buttons below stay as they are, because ending a hold is answering
                     the step properly. --}}
                @if ($onHold)
                    <x-filament::badge color="warning">On hold</x-filament::badge>
                @elseif ($mine)
                    <x-filament::badge color="info">You picked this up</x-filament::badge>
                @endif
            </div>

            {{-- What has already been said about this step: why it came back, or why it is
                 on hold. Saying it here rather than only in the case's history is the whole
                 reason somebody is made to type it. --}}
            @if ($whatWasSaid)
                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                    {{ $whatWasSaid }}
                </p>
            @endif

            {{-- Escalation adds people and removes nobody, so this card has to say it
                 arrived rather than let it read as a job that has been moved onto this
                 person. --}}
            @if ($escalated)
                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                    This came to you because it is past its deadline. It is still open to
                    the people it belonged to, and it stays theirs — you have been added,
                    not handed it.
                </p>
            @endif

            {{-- Marked for the same reason as the two above: a card that arrived because
                 you are standing in for a colleague looks exactly like your own work, and
                 this is the one of the three where somebody else's decision is being
                 made. --}}
            @if ($awayPerson)
                <p class="text-sm font-medium text-amber-600 dark:text-amber-400">
                    This is {{ $awayPerson }}'s to decide. You have it because you are
                    covering for {{ $awayPerson }} while they are away, and whatever you
                    answer is recorded in both names. It stops reaching you when the cover
                    ends.
                </p>
            @endif

            {{-- The whole reason this is marked: a step that reached this list because the
                 company named a stand-in looks exactly like one that is genuinely this
                 person's job, and approving the second believing it was the first is the
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

            {{-- Who it is about, before what it asks. Somebody clearing an exit under a
                 two-day clock cannot decide anything without the person's details in front
                 of them, and they will not go and look them up in another screen. --}}
            @include('filament.pages.partials.subject-panel', ['case' => $case])

            {{-- What this step asks. A step with no form asks nothing and shows nothing,
                 which is right for a sign-off that is only a decision. --}}
            @php($questions = $page->questionsOn($waiting))

            @if ($questions->isNotEmpty())
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

            {{-- And what earlier steps already attached, so a clearance is verified rather
                 than taken on trust. Whoever holds a step of a case may open its
                 documents. --}}
            @include('filament.pages.partials.case-documents', [
                'case' => $case,
                'documents' => $page->documentsOn($waiting),
            ])
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @unless ($mine)
                {{-- Picking it up is what stops two people in a shared queue both working
                     on the same thing. --}}
                <x-filament::button
                    color="gray"
                    wire:click="pickUp({{ $case->getKey() }}, {{ $step->sequence }})"
                    wire:loading.attr="disabled"
                >
                    Pick it up
                </x-filament::button>
            @endunless

            {{-- Approving is one press. Every other outcome asks for a reason first: the
                 engine refuses a hold and a send-back without one, and a rejection with no
                 words on it tells the person whose request ended nothing at all. --}}
            @foreach ($step->allowed_outcomes ?? [] as $outcome)
                {{-- Nothing offers a step the state it is already in. A clearance on hold
                     needs answering, not holding again, and the reason it was held is on
                     the card above. --}}
                @continue ($onHold && $outcome === 'held')

                <x-filament::button
                    :color="$outcome === 'approved' ? 'success' : ($outcome === 'rejected' ? 'danger' : 'gray')"
                    wire:click="{{ $outcome === 'approved' ? 'decide' : 'askFor' }}({{ $case->getKey() }}, {{ $step->sequence }}, '{{ $outcome }}')"
                    wire:loading.attr="disabled"
                >
                    {{ $page->buttonFor($outcome) }}
                </x-filament::button>
            @endforeach
        </div>
    </div>

    {{-- The reason, asked between the button and the decision rather than beside a form
         nobody is filling in. Only one card asks at a time. --}}
    @php($asked = $case->getKey().':'.$step->sequence)

    @if (str_starts_with((string) $page->asking, $asked.':'))
        @php($outcome = explode(':', $page->asking)[2])
        @php($reason = "reasons.{$case->getKey()}.{$step->sequence}")
        @php($goesBackTo = $outcome === 'sent_back' ? $page->whereItCanGoBackTo($waiting) : collect())

        <div style="display: grid; row-gap: 1rem; margin-top: 1rem;">
            {{-- Asked only where there is genuinely a choice. From the branch approval
                 there is one place to go and the card does not make anybody read a list of
                 one. --}}
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
                :label="$page->asksFor($outcome)"
                :required="$outcome !== 'rejected'"
                :state-path="$reason"
            >
                {{-- `fi-fo-textarea` on the wrapper and no class on the box itself, for the
                     reason the question partial gives: Filament styles a one-line box with
                     an element selector, so the same class on a textarea matches
                     nothing. --}}
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
                    {{ $page->buttonFor($outcome) }}
                </x-filament::button>

                <x-filament::button color="gray" wire:click="stopAsking">
                    Cancel
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
