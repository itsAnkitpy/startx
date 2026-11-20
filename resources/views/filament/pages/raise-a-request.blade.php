{{--
    The screen a request is started from.

    Nothing on this page names a process or a question. The list is the live processes
    this person may start, and the form under it is the first step's own form, drawn by
    the same partial the queue screen draws a clearance with. A client who adds a question
    to their hiring request gets it here on the next page load.

    An exit is the one exception: its four questions are what the case itself stores and
    the law reads, so they are the same for every client and are written here.

    Only Filament's own components reach the browser, so spacing is written as a plain
    style rather than as a utility class.
--}}
<x-filament-panels::page>
    @php($processes = $this->processes())
    @php($chosen = $this->chosen())
    @php($questions = $this->questions())

    <x-filament::section>
        <div style="display: grid; row-gap: 1.25rem;">
            <x-filament-forms::field-wrapper
                id="raise-process"
                label="What do you want to raise"
                :required="true"
                state-path="processId"
            >
                <x-filament::input.wrapper :valid="! $errors->has('processId')">
                    <x-filament::input.select id="raise-process" wire:model.live="processId">
                        <option value="">Choose one</option>
                        @foreach ($processes as $process)
                            <option value="{{ $process->getKey() }}">{{ $process->name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>

            @if ($chosen && $this->isAnExit())
                {{-- An exit answers no step when it is raised: these four are what the case
                     itself carries, and the first step is somebody else's. --}}
                <x-filament::fieldset label="Who is leaving, and when">
                    <div style="display: grid; row-gap: 1rem;">
                        <x-filament-forms::field-wrapper id="exit-leaver" label="Who is leaving" :required="true" state-path="leaverId">
                            <x-filament::input.wrapper :valid="! $errors->has('leaverId')">
                                <x-filament::input.select id="exit-leaver" wire:model.live="leaverId">
                                    <option value="">Choose who</option>
                                    @foreach ($this->leavers() as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </x-filament-forms::field-wrapper>

                        <x-filament-forms::field-wrapper id="exit-last-day" label="Last working day" :required="true" state-path="lastWorkingDay">
                            <x-filament::input.wrapper :valid="! $errors->has('lastWorkingDay')">
                                <x-filament::input type="date" id="exit-last-day" wire:model.live="lastWorkingDay" />
                            </x-filament::input.wrapper>
                            <p style="font-size: 0.75rem;">
                                Everything owed to them is due two working days after this, counted on their own office's calendar.
                            </p>
                        </x-filament-forms::field-wrapper>

                        @if ($this->isTheirOwn())
                            <p style="font-size: 0.875rem;">Your own exit is recorded as a resignation.</p>
                        @elseif ($this->leaverId)
                            <x-filament-forms::field-wrapper id="exit-ended-by" label="How their employment is ending" :required="true" state-path="employmentEndedBy">
                                <x-filament::input.wrapper :valid="! $errors->has('employmentEndedBy')">
                                    <x-filament::input.select id="exit-ended-by" wire:model.live="employmentEndedBy">
                                        <option value="">Choose one</option>
                                        @foreach (\App\Models\ProcessCase::WaysEmploymentEnds as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                                <p style="font-size: 0.75rem;">
                                    Whether a gratuity deadline is tracked turns on this.
                                </p>
                            </x-filament-forms::field-wrapper>
                        @endif

                        <x-filament-forms::field-wrapper id="exit-personal-email" label="Personal email" :required="true" state-path="personalEmail">
                            <x-filament::input.wrapper :valid="! $errors->has('personalEmail')">
                                <x-filament::input type="email" id="exit-personal-email" wire:model.live.blur="personalEmail" />
                            </x-filament::input.wrapper>
                            <p style="font-size: 0.75rem;">
                                Their relieving letter and settlement are sent here once their sign-in has ended. Check it is one they still use.
                            </p>
                        </x-filament-forms::field-wrapper>
                    </div>
                </x-filament::fieldset>

                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                    <x-filament::button color="success" wire:click="raise" wire:loading.attr="disabled">
                        Raise the exit
                    </x-filament::button>

                    <span wire:loading wire:target="raise" style="font-size: 0.875rem;">
                        Raising…
                    </span>
                </div>
            @elseif ($chosen)
                {{-- A first step that asks nothing is a request with no detail on it, which
                     is legitimate — an access request that is only "I am asking" — so the
                     button is drawn either way and the fieldset only when there is
                     something in it. --}}
                @if ($questions->isNotEmpty())
                    <x-filament::fieldset label="What this asks for">
                        <div style="display: grid; row-gap: 1rem;">
                            @foreach ($questions as $field)
                                @include('filament.pages.partials.step-question', [
                                    'field' => $field,
                                    'under' => 'answers',
                                ])
                            @endforeach
                        </div>
                    </x-filament::fieldset>
                @endif

                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                    <x-filament::button
                        color="success"
                        wire:click="raise"
                        wire:loading.attr="disabled"
                    >
                        Raise it
                    </x-filament::button>

                    <span wire:loading wire:target="raise" style="font-size: 0.875rem;">
                        Raising…
                    </span>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
