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
                <textarea id="{{ $id }}" rows="3" wire:model="{{ $path }}"></textarea>
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
                    wire:model="{{ $path }}"
                />
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Date)
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input type="date" :id="$id" wire:model="{{ $path }}" />
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Boolean)
            {{-- A list rather than a tick box, because "no" has to be something a person
                 chose. A tick box left alone cannot be told apart from one nobody read,
                 and "was the mailbox switched off" is exactly the question where that
                 difference is the whole point. --}}
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input.select :id="$id" wire:model="{{ $path }}">
                    <option value="">Not answered</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
            @break

        @case (\App\Models\FormField::Select)
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input.select :id="$id" wire:model="{{ $path }}">
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
                            wire:model="{{ $path }}"
                        />
                        {{ $option['label'] ?? ($option['value'] ?? '') }}
                    </label>
                @endforeach
            </div>
            @break

        @case (\App\Models\FormField::UserPicker)
        @case (\App\Models\FormField::OrgUnitPicker)
        @case (\App\Models\FormField::DesignationPicker)
            {{-- A list of the client's own rows, never free text. Free text is what turns
                 one designation into "Sr. Manager", "Senior Manager" and "Sr Manager"
                 inside a year, at which point no report works. --}}
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input.select :id="$id" wire:model="{{ $path }}">
                    <option value="">Not answered</option>
                    @foreach ($this->optionsFor($field) as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            @break

        @default
            <x-filament::input.wrapper :valid="$valid">
                <x-filament::input type="text" :id="$id" wire:model="{{ $path }}" />
            </x-filament::input.wrapper>
    @endswitch
</x-filament-forms::field-wrapper>
