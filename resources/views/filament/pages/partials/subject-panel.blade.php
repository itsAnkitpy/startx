{{--
    The person a step's form is about, as the case froze them.

    Written once and included wherever a step is shown, because the same details belong
    above every form of every process and a second version of them would drift from this
    one. Deliberately not configurable: a per-step choice of which details to show is a
    setting nobody has asked for, on a panel that costs nothing to render whole.

    Sized and spaced with plain styles rather than utility classes. Filament 4 ships only
    its own `fi-` classes compiled, and this project has no front-end build, so a `text-sm`
    or a `flex` written here reaches the browser as nothing at all — the same reason the
    question and document partials beside this one are written the same way.

    Expects `$case`.
--}}
@php($panel = (new \App\Process\SubjectPanel)->of($case))

<x-filament::fieldset label="Who this is about">
    <div style="display: grid; row-gap: 0.5rem;">
        @if ($panel['who'] !== null)
            <p style="font-size: 0.875rem; font-weight: 600;">{{ $panel['who'] }}</p>
        @endif

        @if ($panel['instead'] !== null)
            <p style="font-size: 0.875rem;">{{ $panel['instead'] }}</p>
        @endif

        @if ($panel['facts'] !== [])
            <div style="display: grid; grid-template-columns: max-content 1fr; column-gap: 1.5rem; row-gap: 0.375rem; font-size: 0.875rem;">
                @foreach ($panel['facts'] as $label => $value)
                    <span style="opacity: 0.7;">{{ $label }}</span>
                    <span style="font-weight: 500;">{{ $value }}</span>
                @endforeach
            </div>

            {{-- The whole claim of the panel, said on the panel. Without this line a
                 designation read a year after the exit closed looks like today's. --}}
            <p style="font-size: 0.75rem; opacity: 0.7;">
                As they were when this case opened on {{ $case->opened_at->format('j F Y') }}, not as they are today.
            </p>
        @endif
    </div>
</x-filament::fieldset>
