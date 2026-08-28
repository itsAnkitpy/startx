{{--
    What the person acting on a step has to know before they can decide anything — the
    person the case is about as the case froze them, or, on a case about a vacancy, what
    the request itself asked for.

    Written once and included wherever a step is shown, because the same details belong
    above every form of every process and a second version of them would drift from this
    one. Deliberately not configurable: a per-step choice of which details to show is a
    setting nobody has asked for, on a panel that costs nothing to render whole.

    The heading and the closing line both come from the panel rather than being written
    here. "Who this is about" is wrong above an empty chair, and "as they were when this
    case opened" is a claim about somebody's own record that a request's answers cannot
    make — so the panel decides both and this draws what it is given.

    Sized and spaced with plain styles rather than utility classes, the same way the
    question and document partials beside this one are written.

    Expects `$case`.
--}}
@php($panel = (new \App\Process\SubjectPanel)->of($case))

<x-filament::fieldset :label="$panel['heading']">
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
        @endif

        @if ($panel['asOf'] !== null)
            <p style="font-size: 0.75rem; opacity: 0.7;">{{ $panel['asOf'] }}</p>
        @endif
    </div>
</x-filament::fieldset>
