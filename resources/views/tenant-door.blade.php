{{--
    The page a visitor gets when the address names no client company we have, or one
    whose access is switched off.

    It carries the same ground as the sign-in page, and like that page it needs no
    front-end build: a wrong address must never depend on assets having been compiled.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — StartX</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <meta name="theme-color" content="#0a0e14">

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
            -moz-osx-font-smoothing: grayscale;
        }

        main {
            position: relative;
            z-index: 2;
            width: min(30rem, 100%);
            padding: 38px 34px;
            border: 1px solid var(--sx-hair);
            border-radius: 22px;
            background: var(--sx-panel);
            -webkit-backdrop-filter: blur(24px) saturate(140%);
            backdrop-filter: blur(24px) saturate(140%);
            box-shadow:
                0 40px 90px -30px rgba(0, 0, 0, 0.85),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
            text-align: center;
        }

        .sx-wordmark {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
            font-family: var(--sx-display);
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .sx-wordmark svg {
            height: 24px;
            width: auto;
            fill: var(--sx-text);
            filter: drop-shadow(0 0 14px rgba(110, 136, 255, 0.45));
        }

        .sx-wordmark b { font-weight: inherit; color: var(--sx-primary-bright); }

        h1 {
            margin: 0 0 0.6rem;
            font-family: var(--sx-display);
            font-size: 1.35rem;
            font-weight: 600;
        }

        p {
            margin: 0;
            color: var(--sx-muted);
            font-size: 0.92rem;
            line-height: 1.65;
        }

        form { margin-top: 22px; }

        button {
            width: 100%;
            padding: 12px 18px;
            border: 1px solid var(--sx-hair);
            border-radius: 12px;
            background: var(--sx-primary-bright);
            color: var(--sx-ground);
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover { filter: brightness(1.08); }
    </style>
</head>
<body>
    <main>
        <span class="sx-wordmark">
            <x-brand-mark />
            Start<b>X</b>
        </span>

        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>

        {{-- A link that has run out of time or opens is the one refusal with a way
             forward, and the way forward is always a new link to the address already on
             the record — never to one typed here. --}}
        @isset($askAgainFor)
            <form method="POST" action="{{ route('step-link.again', $askAgainFor) }}">
                @csrf
                <button type="submit">Send me a new link</button>
            </form>
        @endisset
    </main>
</body>
</html>
