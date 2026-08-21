{{--
    The sign-in page, reached on a client company's own address.

    The form itself is `$this->content` — Filament's own schema, untouched. That is what
    keeps the throttling, the failed-attempt messages, remember-me and the two-factor
    challenge exactly as Filament wrote them; only the shell around it is ours. Nothing
    here needs a front-end build: Filament ships its own compiled stylesheet, and the
    brand ground is inlined.

    The company's name is shown because a person who has arrived at the wrong company's
    address has no other way to notice. It is put here by the middleware that reads the
    subdomain, so this page never asks the database for it.
--}}
<div class="sx-signin">
    {{--
        The page is designed dark. Filament serves the panel in whichever theme the
        person last chose, and its own Alpine boot re-applies that choice after the
        document has rendered — so setting a class on the server is not enough on its
        own. Setting the store once Alpine has finished starting is: Filament's own
        effect then paints dark, and the stored preference is never written over, so the
        panel still opens in the theme they picked.
    --}}
    <script>
        document.addEventListener('alpine:initialized', () => window.Alpine.store('theme', 'dark'));
    </script>

    <x-brand-backdrop />

    <style>
        .sx-signin {
            position: fixed;
            inset: 0;
            z-index: 1;
            display: flex;
            overflow: hidden;
            font-family: var(--sx-body);
            color: var(--sx-text);
            background: var(--sx-ground);
            background-image:
                radial-gradient(680px 460px at 14% 18%, rgba(110, 136, 255, 0.18), transparent 60%),
                radial-gradient(560px 520px at 88% 82%, rgba(143, 163, 255, 0.12), transparent 62%);
            /* Native controls — the inputs, the checkbox, the browser's autofill
               shading — have to render dark whatever the panel's saved theme is. */
            color-scheme: dark;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* ── Brand side ──────────────────────────── */
        .sx-brand {
            position: relative;
            z-index: 2;
            flex: 1 1 56%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 26px;
            padding: 6vh 5vw;
        }

        .sx-brand-mark {
            width: clamp(76px, 7vw, 112px);
            height: auto;
            fill: #fff;
            filter: drop-shadow(0 0 26px rgba(110, 136, 255, 0.45));
        }

        /*
            The mark draws itself, then fills — the same arrival the earlier Summerhill
            sign-in page had, and the reason it reads as a loader: the outline is being
            traced while the page is still settling, so the wait has something to watch.

            It works by hiding the whole outline behind a dash longer than the path and
            then walking that dash off the end. 2600 is comfortably longer than either
            path in this mark, so any value at or above the true length draws the same.
        */
        .sx-brand-mark path {
            stroke: #fff;
            stroke-width: 7;
            fill: transparent;
            stroke-dasharray: 2600;
            stroke-dashoffset: 2600;
            animation:
                sx-draw 1.6s cubic-bezier(.6, .05, .2, 1) forwards .2s,
                sx-fill .9s ease forwards 1.5s;
        }

        @keyframes sx-draw { to { stroke-dashoffset: 0; } }
        @keyframes sx-fill { to { fill: #fff; } }

        /* The rest arrives behind the mark, in reading order, so the eye is walked from
           it to the form rather than being handed the whole page at once. `both` holds
           the first frame through the delay, so nothing flashes in before its turn. */
        @keyframes sx-up { from { opacity: 0; transform: translateY(18px); } }

        .sx-brand h1 {
            animation: sx-up .8s cubic-bezier(.2, .7, .2, 1) both .9s;
            margin: 0;
            font-family: var(--sx-display);
            font-size: clamp(2.2rem, 5vw, 3.6rem);
            font-weight: 600;
            line-height: 1.02;
            letter-spacing: 0.06em;
        }

        .sx-brand h1 b { font-weight: inherit; color: var(--sx-primary-bright); }

        .sx-brand h1 small {
            display: block;
            margin-top: 14px;
            padding-left: 4px;
            font-size: 0.24em;
            font-weight: 500;
            letter-spacing: 0.38em;
            color: var(--sx-primary-bright);
        }

        .sx-tag {
            animation: sx-up .8s cubic-bezier(.2, .7, .2, 1) both 1.05s;
            max-width: 34ch;
            margin: 0;
            color: var(--sx-muted);
            font-size: clamp(0.95rem, 1.3vw, 1.15rem);
            line-height: 1.6;
        }

        .sx-tag b { color: var(--sx-text); font-weight: 600; }

        /* ── Card side ───────────────────────────── */
        .sx-auth {
            position: relative;
            z-index: 2;
            flex: 1 1 44%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5vh 4vw;
        }

        .sx-card {
            animation: sx-up .85s cubic-bezier(.2, .7, .2, 1) both .35s;
            width: min(26rem, 100%);
            padding: 38px 34px;
            border: 1px solid var(--sx-hair);
            border-radius: 22px;
            background: var(--sx-panel);
            -webkit-backdrop-filter: blur(24px) saturate(140%);
            backdrop-filter: blur(24px) saturate(140%);
            box-shadow:
                0 40px 90px -30px rgba(0, 0, 0, 0.85),
                inset 0 1px 0 rgba(255, 255, 255, 0.06),
                0 0 70px -24px rgba(110, 136, 255, 0.4);
        }

        .sx-card h2 {
            margin: 0 0 4px;
            font-family: var(--sx-display);
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: #fff;
        }

        .sx-card-sub {
            margin: 0 0 26px;
            color: var(--sx-muted);
            font-size: 0.88rem;
            line-height: 1.55;
        }

        .sx-card-sub b { color: var(--sx-text); font-weight: 600; }

        .sx-card-foot {
            margin-top: 26px;
            text-align: center;
            font-size: 0.72rem;
            letter-spacing: 0.02em;
            color: rgba(148, 163, 184, 0.65);
        }

        /*
            Somebody who has asked their machine for less movement gets the mark already
            drawn and filled, not a logo that never finishes.
        */
        @media (prefers-reduced-motion: reduce) {
            .sx-brand-mark path {
                animation: none;
                stroke-dashoffset: 0;
                fill: #fff;
            }

            .sx-brand h1, .sx-tag, .sx-card {
                animation: none;
                opacity: 1;
                transform: none;
            }
        }

        /* ── Responsive ──────────────────────────── */

        /* A short desktop window scrolls rather than clipping the card. */
        @media (min-width: 901px) and (max-height: 700px) {
            .sx-signin { overflow-y: auto; }
            .sx-brand { padding: 4vh 5vw; gap: 18px; }
            .sx-auth { padding: 4vh 4vw; }
        }

        /* Stacked: scrolling goes back to the document. */
        @media (max-width: 900px) {
            .sx-signin {
                position: static;
                flex-direction: column;
                min-height: 100vh;
                overflow: visible;
            }
            .sx-brand { flex: none; align-items: center; text-align: center; padding: 8vh 8vw 3vh; }
            .sx-tag { margin-inline: auto; }
            .sx-auth { flex: none; padding: 2vh 6vw 9vh; }
        }

        @media (max-width: 480px) {
            .sx-card { padding: 28px 22px; }
            .sx-brand h1 small { letter-spacing: 0.28em; }
        }
    </style>

    <section class="sx-brand">
        <x-brand-mark class="sx-brand-mark" />

        <h1>Start<b>X</b><small>BY SUMMERHILL TECHNOLOGIES</small></h1>

        <p class="sx-tag">
            From the first request to the final clearance — every hire and every exit,
            <b>one connected workspace</b>.
        </p>
    </section>

    <section class="sx-auth">
        <div class="sx-card">
            <h2>{{ $this->getHeading() }}</h2>

            <p class="sx-card-sub">
                @isset($tenant)
                    Sign in to <b>{{ $tenant->name }}</b> with your work email address.
                @else
                    Sign in with your work email address.
                @endisset
            </p>

            {{ $this->content }}

            <p class="sx-card-foot">
                &copy; {{ date('Y') }} Summerhill Technologies
            </p>
        </div>
    </section>
</div>
