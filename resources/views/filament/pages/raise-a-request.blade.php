{{--
    The screen a request is started from.

    Nothing on this page names a process or a question. The list is the live processes
    this person may start, and the form under it is the first step's own form, drawn by
    the same partial the queue screen draws a clearance with. A client who adds a question
    to their hiring request gets it here on the next page load.

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

            @if ($chosen)
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
