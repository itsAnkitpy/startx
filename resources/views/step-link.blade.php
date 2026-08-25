{{--
    The one page somebody with no account ever sees.

    They have no login, no queue and no other screen in this product, so everything they
    need to answer the question is on this page and nothing else is: which company is
    asking, what the step is, and the answers the step allows. Like the wrong-address page
    it needs no front-end build — somebody outside the company must never meet a blank
    page because assets were not compiled.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $step->name }} — {{ $case->tenant->name }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <meta name="theme-color" content="#0a0e14">

    {{-- Nothing here should be indexed or carried to another site: the address itself is
         the permission, and a referrer header hands it to whatever is linked next. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">

    <x-brand-backdrop />

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem;
            font-family: var(--sx-body);
            color: var(--sx-text);
            background: var(--sx-ground);
            background-image:
                radial-gradient(680px 460px at 14% 18%, rgba(110, 136, 255, 0.18), transparent 60%),
                radial-gradient(560px 520px at 88% 82%, rgba(143, 163, 255, 0.12), transparent 62%);
            color-scheme: dark;
            -webkit-font-smoothing: antialiased;
        }

        main {
            position: relative;
            z-index: 2;
            width: min(34rem, 100%);
            padding: 38px 34px;
            border: 1px solid var(--sx-hair);
            border-radius: 22px;
            background: var(--sx-panel);
            backdrop-filter: blur(24px) saturate(140%);
            box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.85);
        }

        .sx-wordmark {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
            font-family: var(--sx-display);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .sx-wordmark svg { height: 24px; width: auto; fill: var(--sx-text); }

        .sx-wordmark b { font-weight: inherit; color: var(--sx-primary-bright); }

        h1 { margin: 0 0 0.4rem; font-family: var(--sx-display); font-size: 1.35rem; font-weight: 600; }

        p { margin: 0 0 0.5rem; color: var(--sx-muted); font-size: 0.92rem; line-height: 1.65; }

        label { display: block; margin: 22px 0 8px; font-size: 0.86rem; color: var(--sx-text); }

        textarea {
            width: 100%;
            min-height: 7rem;
            padding: 12px 14px;
            border: 1px solid var(--sx-hair);
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.25);
            color: var(--sx-text);
            font: inherit;
            resize: vertical;
        }

        .sx-answers { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }

        button {
            flex: 1 1 10rem;
            padding: 12px 18px;
            border: 1px solid var(--sx-hair);
            border-radius: 12px;
            background: var(--sx-primary-bright);
            color: var(--sx-ground);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button.sx-no { background: transparent; color: var(--sx-text); }

        button:hover { filter: brightness(1.08); }

        .sx-note { margin-top: 22px; font-size: 0.82rem; }
    </style>
</head>
<body>
    <main>
        <span class="sx-wordmark">
            <x-brand-mark />
            Start<b>X</b>
        </span>

        <h1>{{ $step->name }}</h1>

        <p>
            {{ $case->tenant->name }} has asked you to answer this
            @if ($case->subject)
                as part of {{ $case->subject->name }}'s {{ strtolower($case->template->name) }}.
            @else
                as part of {{ strtolower($case->template->name) }}.
            @endif
        </p>

        <form method="POST" action="{{ route('step-link.submit', $token) }}">
            @csrf

            <label for="note">Anything you want on the record (optional)</label>
            <textarea id="note" name="note" maxlength="2000"></textarea>

            <div class="sx-answers">
                @foreach ($step->allowed_outcomes ?? [] as $outcome)
                    <button
                        type="submit"
                        name="outcome"
                        value="{{ $outcome }}"
                        @class(['sx-no' => $outcome !== 'approved'])
                    >{{ ucfirst(str_replace('_', ' ', $outcome)) }}</button>
                @endforeach
            </div>
        </form>

        <p class="sx-note">
            You can answer this once. The link works for {{ $lastsHours }} hours from when it was
            sent and can be opened {{ $opens }} times; if it stops working it will offer you a new one.
        </p>
    </main>
</body>
</html>
