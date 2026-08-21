{{--
    A full-bleed shell for the sign-in page. It keeps Filament's own document — its
    stylesheet, its scripts, Livewire — but skips the centred-card layout, so the page
    itself owns the whole viewport.

    `$livewire` is defaulted because Filament renders a layout without it in a few
    places, and the base layout declares it as a prop rather than requiring it.
--}}
@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    {{ $slot }}
</x-filament-panels::layout.base>
