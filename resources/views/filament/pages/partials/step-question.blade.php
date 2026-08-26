{{--
    One question on one step, rendered by its type.

    Everything here is a Filament component rather than utility classes: this project has
    no front-end build, so the only styles that reach the browser are the ones Filament
    ships compiled. A class we invent here does nothing at all.

    Nothing here is a check either. Everything typed on this page is checked again on the
    server against the client's own question definitions, because the browser is the half
    of this an employee controls.
--}}
@php($path = "answers.{$caseId}.{$sequence}.{$field->key}")
@php($id = "q-{$caseId}-{$sequence}-{$field->key}")
@php($valid = ! $errors->has($path))

{{--
    Every answer reaches the server as it is given, because the server is what decides
    which questions are asked at all: finance says the imprest card came back and the
    question about what it is recovering goes, which cannot happen in the browser. A list
    sends on the change, a typed box when the person leaves it — per-keystroke would be a
    request a letter for nothing. `.live` before `.blur` is not optional in Livewire 4: on
    its own, `.blur` holds the value in the browser and never sends it at all.
--}}

<x-filament-forms::field-wrapper
    :id="$id"
    :label="$field->label"
    :required="$field->required"
    :state-path="$path"
>
    @switch ($field->type)
        @case (\App\Models\FormField::Textarea)
            {{-- `fi-fo-textarea` on the wrapper, and no class on the box itself. Filament
                 styles a one-line box with `input.fi-input`, which is an element selector:
                 the same class on a textarea matches nothing at all, and the browser draws
                 its own twenty-character box inside ours. A paragraph box is styled through
                 the wrapper instead, which is what Filament's own does. --}}
            <x-filament::input.wrapper :valid="$valid" class="fi-fo-textarea">
                <textarea id="{{ $id }}" rows="3" wire:model.live.blur="{{ $path }}"></textarea>
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Number)
        @case (\App\Models\FormField::Money)
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input
                    type="number"
                    :id="$id"
                    :step="$field->type === \App\Models\FormField::Money ? '0.01' : 'any'"
                    :min="$field->type === \App\Models\FormField::Money ? '0' : null"
                    wire:model.live.blur="{{ $path }}"
                />
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Date)
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input type="date" :id="$id" wire:model.live="{{ $path }}" />
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Boolean)
            {{-- A list rather than a tick box, because "no" has to be something a person
                 chose. A tick box left alone cannot be told apart from one nobody read,
                 and "was the mailbox switched off" is exactly the question where that
                 difference is the whole point. --}}
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input.select :id="$id" wire:model.live="{{ $path }}">
                    <option value="">Not answered</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Select)
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input.select :id="$id" wire:model.live="{{ $path }}">
                    <option value="">Not answered</option>
                    @foreach ($field->options ?? [] as $option)
                        <option value="{{ $option['value'] ?? '' }}">{{ $option['label'] ?? ($option['value'] ?? '') }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Multiselect)
            <div style="display: grid; row-gap: 0.375rem;">
                @foreach ($field->options ?? [] as $option)
                    <label style="display: flex; align-items: center; column-gap: 0.5rem;">
                        <x-filament::input.checkbox
                            :valid="$valid"
                            value="{{ $option['value'] ?? '' }}"
                            wire:model.live="{{ $path }}"
                        />
                        {{ $option['label'] ?? ($option['value'] ?? '') }}
                    </label>
                @endforeach
            </div>
            @break

        @case (\App\Models\FormField::File)
            {{-- Filament's own file field draws itself in JavaScript this project does not
                 build, and a browser's own file box draws as bare text once Filament's
                 stylesheet has reset it — no border, no button, nothing anybody would
                 think to click. So the real box is taken off the screen and a Filament
                 button points at it: the same button as Approve and Pick it up beside it,
                 in the client's own colours and in dark mode, with no styles of our own.

                 `wire:model` on a file box uploads the moment one is chosen — there is no
                 `.live` to add and no button to press — and the file goes to Livewire's
                 holding area, not to ours: nothing is written anywhere it survives until
                 this step is actually decided. --}}
            @php($attached = $this->attachedTo($caseId, $sequence, $field->key))

            <div style="display: grid; row-gap: 0.5rem; justify-items: start;">
                <input
                    type="file"
                    id="{{ $id }}"
                    wire:model="{{ $path }}"
                    style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0;"
                />

                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; max-width: 100%;">
                    <x-filament::button
                        tag="label"
                        :for="$id"
                        color="gray"
                        size="sm"
                        icon="heroicon-m-paper-clip"
                    >
                        {{ $attached ? 'Choose a different file' : 'Choose a file' }}
                    </x-filament::button>

                    <span wire:loading wire:target="{{ $path }}" style="font-size: 0.875rem;">
                        Attaching…
                    </span>

                    {{-- What is attached, said plainly. A file box that has been taken off
                         the screen cannot show it, and this page redraws on every answer
                         given, so without this there is nothing telling a document apart
                         from none. --}}
                    @if ($attached)
                        <x-filament::badge
                            color="success"
                            icon="heroicon-m-paper-clip"
                            style="max-width: 100%; overflow-wrap: anywhere;"
                        >
                            {{ $attached }}
                        </x-filament::badge>
                    @endif
                </div>

                <p style="font-size: 0.75rem;">
                    PDF, JPEG, PNG, Word or Excel, up to
                    {{ (int) (\App\Process\StepForm::DocumentKilobytes / 1024) }} MB.
                </p>
            </div>
            @break

        @case (\App\Models\FormField::UserPicker)
        @case (\App\Models\FormField::OrgUnitPicker)
        @case (\App\Models\FormField::DesignationPicker)
            {{-- A list of the client's own rows, never free text. Free text is what turns
                 one designation into "Sr. Manager", "Senior Manager" and "Sr Manager"
                 inside a year, at which point no report works. --}}
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input.select :id="$id" wire:model.live="{{ $path }}">
                    <option value="">Not answered</option>
                    @foreach ($this->optionsFor($field) as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            @break

        @default
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input type="text" :id="$id" wire:model.live.blur="{{ $path }}" />
            </x-filament::input.wrapper>
    @endswitch
</x-filament-forms::field-wrapper>
