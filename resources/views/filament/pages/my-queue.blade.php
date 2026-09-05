{{--
    What is waiting on the person signed in.

    Every line here is worked out on load rather than read from a table, which is the
    whole claim of the module behind it. A step nobody has picked up has no row anywhere
    and still appears; a step somebody else took has a row and does not.

    The list itself, its filters and its order are the table on the page class; each row
    is drawn by `filament/tables/columns/what-is-waiting`.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
