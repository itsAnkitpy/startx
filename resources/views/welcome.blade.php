{{--
    The front page. Two jobs, and they are for two different people.

    Somebody who already works at a client company needs one thing: the way in. That is the
    panel beside the headline, and it changes with the address. At a company's own address there
    is nothing to ask, so it offers that company's sign-in page. On the bare domain it asks which
    company, because signing in only happens at a company's address and a form here could never
    let anybody through. Darwinbox asks the same question the same way; every product checked on
    20 August 2026 keeps sign-in underneath a company's own address.

    Somebody who has never heard of us needs everything below the hero: what the product does,
    why it exists, and what it deliberately does not do.

    Every claim on this page comes from `PRD/overview.md` and `PRD/market-position.md`. Two rules
    from those documents must not be relaxed by a later edit:

    - **Never write "48 hours".** The statute says two *working* days, which is a different date
      around weekends and holidays. This is the most customer-facing page in the product and so
      the worst place to carry the shorter, wrong phrase.
    - **No penalty figures, and no claim that a breach is punished.** `market-position.md` says
      those numbers need a lawyer's sign-off first, and that enforcement is softer than a
      compliance pitch wants. What is defensible is that the old thirty-to-forty-five-day habit
      is no longer lawful on paper.

    There are no customer logos, no testimonials and no numbers about ourselves, because we have
    none and inventing them is the one thing a page selling an audit trail cannot do. The honest
    limits section stands in their place.

    ── The redesign of 21 August 2026, and why the page is shaped the way it is ──

    The previous version was honest and worked, and read as one shape repeated five times: label,
    heading, paragraph, row of identical cards. The fix is rhythm rather than decoration, so
    every section now differs from its neighbours in three ways at once — height, density and the
    kind of object it holds:

    - The hero is a two-column split, headline against the sign-in panel, not a centred stack.
    - A single tight strip carries the statute, and is deliberately the shortest thing here.
    - The queue-versus-at-once picture is the widest thing here, with the deadline drawn across
      it as a line the queue visibly falls past. It was already the only place the page showed
      rather than told; the redesign makes it the centre instead of a detail.
    - One band is a single sentence at display size, and holds nothing else.
    - "What it runs" is three tiles of two different sizes, the largest holding a drawn
      clearance board — labelled as a drawing, because we do not have a screen to show yet.
    - "What is different" has no cards at all: numbered rows separated by hairlines.
    - The limits are the densest block on the page, and the closing is the emptiest.

    Amber is the only addition to the palette and it means one thing: the deadline. Nothing else
    on any page is allowed to use it.

    Self-contained, like the sign-in and wrong-address pages: no `@vite`, so it renders before
    anybody has run a front-end build. No JavaScript.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>StartX — hiring, onboarding and exits in one place</title>
    <meta name="description" content="StartX by Summerhill Technologies. One configurable platform for hiring requests, onboarding and exits, with an attributable trail behind every approval and every rupee of a final settlement.">

    {{-- SVG rather than the .ico, which was an empty file. One drawing, every size. --}}
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    {{-- Paints the phone browser's own chrome the same colour as the page, instead of
         leaving a white band above a dark page. --}}
    <meta name="theme-color" content="#0a0e14">

    {{-- What somebody sees when this link is pasted into a chat. Without these it
         previews as a bare address. --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="StartX — hiring, onboarding and exits in one place">
    <meta property="og:description" content="One workspace for hiring requests, onboarding and exits, with an attributable trail behind every approval.">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('favicon.svg') }}">
    <meta name="twitter:card" content="summary">

    <x-brand-backdrop />

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--sx-body);
            color: var(--sx-text);
            background: var(--sx-ground);
            background-image:
                radial-gradient(680px 460px at 14% 8%, rgba(110, 136, 255, 0.20), transparent 60%),
                radial-gradient(560px 520px at 92% 30%, rgba(143, 163, 255, 0.10), transparent 62%);
            overflow-x: hidden;
            /* Native controls, the scrollbar and autofill shading render dark. Without
               it the address box goes yellow-on-white the moment a browser fills it. */
            color-scheme: dark;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /*
            The one colour this page adds to the brand, and it means exactly one thing:
            the two-working-day deadline. Kept local rather than put in the backdrop
            component, so no other page can quietly start using it for something else.
        */
        :root {
            --sx-clock: #f2a83b;
            --sx-clock-bright: #fbc46a;
            --sx-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        /* Everything sits above the drifting glow and the fine grid behind it. */
        .sx-bar, .sx-hero, .sx-strip, .sx-band, .sx-quote, .sx-foot { position: relative; z-index: 2; }

        /*
            Two measures rather than one. 72rem is the reading measure for prose and the
            narrow sections; 84rem is for the two things that are meant to feel wide —
            the hero split and the clearance comparison.
        */
        .sx-wrap {
            width: min(72rem, 100%);
            margin-inline: auto;
            padding-inline: 5%;
        }

        .sx-wrap--wide { width: min(84rem, 100%); }

        /* Anything the bar links to has to land below the bar rather than behind it. */
        #sign-in, #why, #runs, #limits { scroll-margin-top: 84px; }

        /* ── Top bar ─────────────────────────────── */

        /* Sticky, because the way in has to be reachable from anywhere on a page this
           long — and it is the only action somebody scrolling already knows they want. */
        .sx-bar {
            position: sticky;
            top: 0;
            z-index: 40;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 15px 5%;
            border-bottom: 1px solid var(--sx-edge);
            background: rgba(10, 14, 20, 0.72);
            -webkit-backdrop-filter: blur(18px) saturate(140%);
            backdrop-filter: blur(18px) saturate(140%);
        }

        .sx-wordmark {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-right: auto;
            font-family: var(--sx-display);
            font-size: 1.4rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--sx-text);
            text-decoration: none;
        }

        .sx-wordmark svg {
            height: 30px;
            width: auto;
            fill: var(--sx-text);
            filter: drop-shadow(0 0 14px rgba(110, 136, 255, 0.45));
        }

        /* Wrapped so the word stays one flex item — without this the bar's 12px gap
           opens up between "Start" and the coloured "X". */
        .sx-wordmark span { display: inline-block; }
        .sx-wordmark b { font-weight: inherit; color: var(--sx-primary-bright); }

        /* Two jumps into the page. Hidden on a phone, where the whole bar has to fit
           beside the wordmark and the one action that matters wins. */
        .sx-nav-links { display: none; gap: 28px; }

        .sx-nav-links a {
            color: var(--sx-muted);
            font-size: 0.88rem;
            text-decoration: none;
            transition: color .2s;
        }

        .sx-nav-links a:hover { color: var(--sx-text); }

        /* ── Hero ────────────────────────────────── */

        /*
            A split, not a centred stack. The argument is on the left at the largest type
            on the page; the one action is on the right, at its own weight, so neither has
            to queue behind the other. They stack on a narrow screen, argument first.
        */
        .sx-hero {
            display: grid;
            gap: 44px;
            padding: 8vh 0 10vh;
        }

        .sx-hero-copy {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 22px;
        }

        .sx-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border: 1px solid var(--sx-edge);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.04);
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
            color: var(--sx-muted);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .sx-badge i {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--sx-primary);
        }

        h1 {
            font-family: var(--sx-display);
            font-size: clamp(2.5rem, 5.4vw, 4.9rem);
            font-weight: 700;
            line-height: 1.02;
            letter-spacing: -0.03em;
        }

        h1 em { font-style: normal; color: var(--sx-primary); }

        /* Lets the browser even out the display lines rather than leaving one word alone
           on the last one. Where it is unsupported the text simply wraps as before. */
        h1, .sx-band h2, .sx-quote-line, .sx-points h3 { text-wrap: balance; }

        .sx-lede {
            max-width: 40rem;
            color: var(--sx-muted);
            font-size: clamp(1rem, 1.3vw, 1.15rem);
            line-height: 1.68;
        }

        .sx-lede b { color: var(--sx-text); font-weight: 600; }

        .sx-chips { display: flex; flex-wrap: wrap; gap: 10px; }

        .sx-chip {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 9px 15px;
            border: 1px solid var(--sx-hair);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.02);
            -webkit-backdrop-filter: blur(6px);
            backdrop-filter: blur(6px);
            color: var(--sx-muted);
            font-size: 0.8rem;
            letter-spacing: 0.03em;
        }

        .sx-chip svg {
            width: 16px;
            height: 16px;
            flex: none;
            fill: none;
            stroke: var(--sx-primary);
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* ── The doorway: the action for somebody who already has an account ── */

        /*
            The only glass panel on the page, and the only thing carrying a deep shadow.
            That is what marks it as the action rather than another card.
        */
        .sx-door {
            width: 100%;
            padding: 28px;
            border: 1px solid var(--sx-hair);
            border-radius: 20px;
            background: var(--sx-panel);
            -webkit-backdrop-filter: blur(24px) saturate(140%);
            backdrop-filter: blur(24px) saturate(140%);
            box-shadow:
                0 40px 90px -30px rgba(0, 0, 0, 0.85),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }

        .sx-door h2 {
            font-family: var(--sx-display);
            font-size: 1.12rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .sx-door p {
            color: var(--sx-muted);
            font-size: 0.9rem;
            line-height: 1.65;
        }

        /* ── Buttons ─────────────────────────────── */
        .sx-nav-btn {
            flex: none;
            padding: 10px 22px;
            border: 1px solid var(--sx-hair);
            border-radius: 8px;
            color: var(--sx-text);
            font-size: 0.92rem;
            font-weight: 500;
            text-decoration: none;
            transition: color .2s, border-color .2s, box-shadow .25s;
        }

        .sx-nav-btn:hover {
            color: var(--sx-primary);
            border-color: var(--sx-primary);
            box-shadow: 0 0 0 1px rgba(110, 136, 255, 0.25);
        }

        .sx-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            margin-top: 16px;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--sx-primary), var(--sx-primary-bright));
            color: var(--sx-ground);
            font-family: var(--sx-display);
            font-size: 0.98rem;
            font-weight: 600;
            letter-spacing: 0.03em;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 12px 32px -8px rgba(110, 136, 255, 0.6);
            transition: transform .15s, box-shadow .25s, filter .2s;
        }

        .sx-cta:hover {
            transform: translateY(-2px);
            filter: brightness(1.06);
            box-shadow: 0 18px 42px -8px rgba(110, 136, 255, 0.78);
        }

        .sx-cta:active { transform: translateY(0); }

        .sx-cta svg {
            width: 18px;
            height: 18px;
            flex: none;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Kept in the markup for a screen reader, out of the way for everyone else —
           the box's own placeholder and the domain beside it already say what it is. */
        .sx-label {
            position: absolute;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            overflow: hidden;
            clip-path: inset(50%);
            white-space: nowrap;
        }

        /* The address box and the fixed part of the address beside it read as one
           control, so the person can see the whole address they are heading for. */
        .sx-address {
            display: flex;
            align-items: stretch;
            margin-top: 14px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.04);
            overflow: hidden;
            transition: border-color .2s, box-shadow .25s, background .2s;
        }

        .sx-address:focus-within {
            border-color: var(--sx-primary);
            background: rgba(110, 136, 255, 0.06);
            box-shadow: 0 0 0 4px rgba(110, 136, 255, 0.16);
        }

        .sx-address input {
            flex: 1 1 auto;
            min-width: 0;
            height: 50px;
            padding: 0 4px 0 16px;
            border: none;
            background: none;
            color: #fff;
            font-family: var(--sx-mono);
            font-size: 0.92rem;
            text-align: right;
            outline: none;
        }

        .sx-address input::placeholder { color: var(--sx-placeholder); }

        .sx-address span {
            display: grid;
            place-items: center;
            padding: 0 16px 0 0;
            color: var(--sx-primary-bright);
            font-family: var(--sx-mono);
            font-size: 0.92rem;
            white-space: nowrap;
        }

        .sx-error {
            margin-top: 10px;
            padding: 9px 13px;
            border: 1px solid rgba(251, 113, 133, 0.35);
            border-radius: 10px;
            background: rgba(251, 113, 133, 0.1);
            color: var(--sx-bad-text);
            font-size: 0.82rem;
            line-height: 1.5;
        }

        /* ── The statute strip: the tightest thing on the page ── */

        /*
            One line, full width, and deliberately shorter than anything else here. It is
            the only place amber appears outside the comparison picture, and it exists so
            the section under the hero is a change of pace rather than another block.
        */
        .sx-strip {
            border-block: 1px solid rgba(242, 168, 59, 0.16);
            background:
                linear-gradient(90deg, rgba(242, 168, 59, 0.07), rgba(242, 168, 59, 0.015) 55%, transparent);
        }

        .sx-strip .sx-wrap {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 18px;
            padding-block: 15px;
            font-family: var(--sx-mono);
            font-size: 0.78rem;
            letter-spacing: 0.02em;
            color: var(--sx-muted);
        }

        .sx-strip b { color: var(--sx-clock-bright); font-weight: 600; }

        .sx-strip i {
            flex: none;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--sx-clock);
            box-shadow: 0 0 0 4px rgba(242, 168, 59, 0.14);
            animation: sx-tick 3.2s ease-in-out infinite;
        }

        @keyframes sx-tick {
            0%, 100% { box-shadow: 0 0 0 4px rgba(242, 168, 59, 0.14); }
            50%      { box-shadow: 0 0 0 8px rgba(242, 168, 59, 0.05); }
        }

        /* Hairline dividers between the three facts, drawn rather than typed so no
           screen reader announces a pile of vertical bars. */
        .sx-strip s {
            flex: none;
            width: 1px;
            height: 12px;
            background: rgba(255, 255, 255, 0.14);
            text-decoration: none;
        }

        /* ── Bands: the sections below the hero ── */

        /*
            No single rhythm any more. Each band names its own height, because a page where
            every section is the same height is the thing being fixed.
        */
        .sx-band { padding-block: clamp(64px, 9vh, 104px); }
        .sx-band--tall { padding-block: clamp(80px, 12vh, 140px); }
        .sx-band--tight { padding-block: clamp(52px, 7vh, 76px); }

        /* A faint ground and hairlines, so the eye can tell where one idea ends without
           a heavy divider. */
        .sx-band--tint {
            border-block: 1px solid var(--sx-edge);
            background: rgba(255, 255, 255, 0.018);
        }

        /* One band gets a glow of its own, and only one — the argument the whole product
           rests on. More than one and it stops meaning anything. */
        .sx-band--lit {
            background:
                radial-gradient(120% 80% at 50% 0%, rgba(110, 136, 255, 0.10), transparent 65%),
                rgba(255, 255, 255, 0.014);
            border-block: 1px solid var(--sx-hair);
        }

        .sx-eyebrow {
            display: block;
            margin-bottom: 14px;
            color: var(--sx-primary-bright);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 2.4px;
            text-transform: uppercase;
        }

        .sx-band h2 {
            max-width: 32ch;
            font-family: var(--sx-display);
            font-size: clamp(1.7rem, 3.4vw, 2.7rem);
            font-weight: 600;
            line-height: 1.14;
            letter-spacing: -0.02em;
        }

        .sx-band h2 em { font-style: normal; color: var(--sx-primary); }

        /*
            The two paragraphs under a heading sit in two columns on a wide screen, which
            is the other half of not looking like a stack: prose that is a block rather
            than a long single ribbon.
        */
        .sx-say { display: grid; gap: 16px; margin-top: 20px; }

        .sx-say p {
            color: var(--sx-muted);
            font-size: clamp(0.98rem, 1.15vw, 1.06rem);
            line-height: 1.72;
        }

        .sx-say p b { color: var(--sx-text); font-weight: 600; }

        /* ── The queue-versus-at-once picture: the widest thing here ── */

        /*
            The one idea on this page a sentence cannot carry: the same seven clearances,
            in a line and side by side, with the deadline drawn across both. Built from
            flex and a pseudo-element rather than an image, so it reflows on a phone and
            needs no build step.
        */
        .sx-flows {
            display: grid;
            gap: 20px;
            margin-top: 42px;
        }

        .sx-flow {
            position: relative;
            padding: 24px 26px 26px;
            border: 1px solid var(--sx-edge);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.022);
        }

        /* The one we are describing as right gets the brand edge; the one we are
           describing as broken stays neutral rather than being coloured as an error,
           because it is how nearly every existing tool works and not a mistake anybody
           made. */
        .sx-flow--ours {
            border-color: var(--sx-hair);
            background:
                linear-gradient(180deg, rgba(110, 136, 255, 0.09), rgba(110, 136, 255, 0.03));
        }

        /* Verdict above the heading rather than beside it. Side by side, the longer of
           the two headings pushed its pill onto a second line and the two panels stopped
           looking like the same object compared. */
        .sx-flow-head {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 18px;
        }

        .sx-flow-head h3 {
            font-family: var(--sx-display);
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .sx-verdict {
            flex: none;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .sx-verdict--slow {
            border: 1px solid rgba(251, 113, 133, 0.3);
            background: rgba(251, 113, 133, 0.1);
            color: var(--sx-bad-text);
        }

        .sx-verdict--fast {
            border: 1px solid rgba(52, 211, 153, 0.3);
            background: rgba(52, 211, 153, 0.1);
            color: var(--sx-good-text);
        }

        .sx-steps { list-style: none; }

        .sx-steps li {
            border: 1px solid var(--sx-edge);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.03);
            color: var(--sx-muted);
            font-size: 0.82rem;
            white-space: nowrap;
        }

        /*
            A queue is drawn as a column, because that is what a queue looks like: each row
            waits for the one above it. Laying the same seven out in a row needed arrows
            between them, and the moment the row wrapped an arrow was left pointing at the
            end of a line.
        */
        .sx-steps--queue { display: grid; gap: 0; }

        .sx-steps--queue li {
            position: relative;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: baseline;
            gap: 16px;
            width: fit-content;
            min-width: 15rem;
            margin-bottom: 14px;
            padding: 7px 13px;
        }

        /* The working day each step lands on. This is the arithmetic the heading claims —
           seven steps in a line is a week — written down instead of asserted, and it is
           what makes the deadline below a line something rather than a decoration. */
        .sx-steps--queue li em {
            color: var(--sx-faint);
            font-family: var(--sx-mono);
            font-size: 0.68rem;
            font-style: normal;
        }

        .sx-steps--queue li:last-child { margin-bottom: 0; }

        /* The line down to the next one. Decoration around real text, so it is drawn
           rather than being a character a screen reader would read out six times. */
        .sx-steps--queue li:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 22px;
            width: 1px;
            height: 14px;
            background: var(--sx-faint);
        }

        /*
            The same seven with nothing between them, wrapped as one block — the shape of
            work that all starts at the same moment. Next to the column above, the height
            difference is the argument.
        */
        .sx-steps--atonce {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        /* The same day language as the queue beside it, said once because there is only
           one day to say. */
        .sx-atonce-day {
            margin-bottom: 12px;
            color: var(--sx-faint);
            font-family: var(--sx-mono);
            font-size: 0.68rem;
        }

        .sx-steps--atonce li {
            padding: 7px 13px;
            border-color: var(--sx-hair);
            background: rgba(110, 136, 255, 0.08);
            color: var(--sx-text);
        }

        /*
            The deadline, drawn as a line across the picture. In the queue it falls after
            the third step and four steps sit visibly past it; beside it the block of seven
            finishes above the line with room to spare. That is the whole argument, and it
            is the reason this section is the widest and the most lit.

            Real text rather than a pseudo-element, because it is a label somebody reading
            with a screen reader needs as much as somebody looking at it.
        */
        .sx-deadline {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 18px 0;
            color: var(--sx-clock-bright);
            font-family: var(--sx-mono);
            font-size: 0.72rem;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .sx-deadline::before,
        .sx-deadline::after {
            content: '';
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--sx-clock), transparent);
        }

        .sx-deadline::before { width: 26px; flex: none; }
        .sx-deadline::after { flex: 1 1 auto; }

        /* Past the deadline the remaining steps recede, so the eye reads them as time
           that has already run out rather than as more of the same list. */
        .sx-past li { opacity: 0.45; }

        /* A note under each panel, in the same monospace as the deadline, holding the
           arithmetic rather than repeating the heading. */
        .sx-flow-note {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--sx-edge);
            color: var(--sx-faint);
            font-family: var(--sx-mono);
            font-size: 0.72rem;
            line-height: 1.7;
        }

        /* ── One sentence at display size, and nothing else ── */

        /*
            The band that carries no cards, no icons and no list. It is here because a page
            that is five screens of blocks needs one place where the eye rests on a single
            thought.
        */
        .sx-quote {
            padding-block: clamp(76px, 13vh, 150px);
            border-block: 1px solid var(--sx-edge);
            background:
                radial-gradient(70% 120% at 50% 50%, rgba(110, 136, 255, 0.07), transparent 70%),
                rgba(0, 0, 0, 0.22);
            text-align: center;
        }

        .sx-quote-line {
            font-family: var(--sx-display);
            font-size: clamp(1.8rem, 5.2vw, 3.9rem);
            font-weight: 600;
            line-height: 1.1;
            letter-spacing: -0.03em;
            color: var(--sx-faint);
        }

        .sx-quote-line em {
            display: block;
            margin-top: 10px;
            font-style: normal;
            color: var(--sx-text);
        }

        .sx-quote-line em b { font-weight: inherit; color: var(--sx-primary); }

        .sx-quote-src {
            margin-top: 30px;
            color: var(--sx-faint);
            font-family: var(--sx-mono);
            font-size: 0.74rem;
            letter-spacing: 0.04em;
        }

        /* ── What it runs: three tiles, two sizes ── */

        /*
            Not a row of equal cards. Exits is the reason the product exists, so it takes
            twice the room and holds a drawn board; the other two are read-and-move-on.
        */
        .sx-tiles {
            display: grid;
            gap: 18px;
            margin-top: 40px;
        }

        .sx-tile {
            padding: 26px 24px;
            border: 1px solid var(--sx-edge);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.022);
            transition: border-color .25s, background .25s;
        }

        .sx-tile:hover {
            border-color: var(--sx-hair);
            background: rgba(110, 136, 255, 0.045);
        }

        .sx-tile--wide {
            border-color: var(--sx-hair);
            background:
                linear-gradient(160deg, rgba(110, 136, 255, 0.07), rgba(255, 255, 255, 0.015) 60%);
        }

        .sx-tile-icon {
            display: grid;
            place-items: center;
            width: 38px;
            height: 38px;
            margin-bottom: 16px;
            border: 1px solid var(--sx-hair);
            border-radius: 10px;
            background: rgba(110, 136, 255, 0.1);
        }

        .sx-tile-icon svg {
            width: 19px;
            height: 19px;
            fill: none;
            stroke: var(--sx-primary-bright);
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .sx-tile h3 {
            margin-bottom: 9px;
            font-family: var(--sx-display);
            font-size: 1.04rem;
            font-weight: 600;
        }

        .sx-tile p {
            color: var(--sx-muted);
            font-size: 0.9rem;
            line-height: 1.68;
        }

        .sx-tile p b { color: var(--sx-text); font-weight: 600; }

        /* ── The drawn clearance board ── */

        /*
            The nearest thing on this page to showing the product, and it says so
            underneath. We have one real screen built and it is not this one, so this is a
            drawing of the shape rather than a screenshot — a page that sells an audit
            trail cannot pass off a picture as a photograph.

            It is one image as far as a screen reader is concerned, with a label that says
            what it shows, because reading seven rows of names and statuses aloud carries
            noise rather than meaning.
        */
        .sx-board {
            margin-top: 22px;
            border: 1px solid var(--sx-hair);
            border-radius: 14px;
            background: rgba(10, 14, 20, 0.6);
            overflow: hidden;
            box-shadow: 0 24px 60px -24px rgba(0, 0, 0, 0.8);
        }

        .sx-board-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px 16px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--sx-edge);
            background: rgba(255, 255, 255, 0.03);
        }

        .sx-board-who {
            font-family: var(--sx-display);
            font-size: 0.88rem;
            font-weight: 600;
        }

        .sx-board-who small {
            display: block;
            margin-top: 2px;
            color: var(--sx-faint);
            font-family: var(--sx-mono);
            font-size: 0.68rem;
            font-weight: 400;
        }

        .sx-board-clock {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 11px;
            border: 1px solid rgba(242, 168, 59, 0.35);
            border-radius: 999px;
            background: rgba(242, 168, 59, 0.1);
            color: var(--sx-clock-bright);
            font-family: var(--sx-mono);
            font-size: 0.68rem;
            white-space: nowrap;
        }

        .sx-board-clock i {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--sx-clock);
        }

        .sx-board-rows { list-style: none; }

        .sx-board-rows li {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 8px 12px;
            padding: 11px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 0.8rem;
        }

        .sx-board-rows li:first-child { border-top: none; }

        .sx-board-dept b {
            display: block;
            font-weight: 500;
            color: var(--sx-text);
        }

        .sx-board-dept span {
            color: var(--sx-faint);
            font-family: var(--sx-mono);
            font-size: 0.68rem;
        }

        .sx-pill {
            flex: none;
            padding: 3px 9px;
            border-radius: 999px;
            font-family: var(--sx-mono);
            font-size: 0.66rem;
            white-space: nowrap;
        }

        .sx-pill--done {
            border: 1px solid rgba(52, 211, 153, 0.28);
            background: rgba(52, 211, 153, 0.09);
            color: var(--sx-good-text);
        }

        .sx-pill--hold {
            border: 1px solid rgba(242, 168, 59, 0.32);
            background: rgba(242, 168, 59, 0.09);
            color: var(--sx-clock-bright);
        }

        .sx-pill--open {
            border: 1px solid var(--sx-edge);
            background: rgba(255, 255, 255, 0.04);
            color: var(--sx-muted);
        }

        .sx-board-foot {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 6px 16px;
            padding: 13px 16px;
            border-top: 1px solid var(--sx-hair);
            background: rgba(110, 136, 255, 0.06);
            font-family: var(--sx-mono);
            font-size: 0.72rem;
            color: var(--sx-muted);
        }

        .sx-board-foot b { color: var(--sx-text); }

        .sx-drawn {
            max-width: 46ch;
            margin-top: 12px;
            color: var(--sx-faint);
            font-size: 0.72rem;
            line-height: 1.6;
        }

        /* ── What is different: no cards at all ── */

        /*
            Numbered rows on hairlines. The number carries the type scale this section
            needs, and dropping the card treatment entirely is what stops the page reading
            as the same object seven times over.
        */
        .sx-points {
            margin-top: 34px;
            border-top: 1px solid var(--sx-edge);
            list-style: none;
            counter-reset: sx-point;
        }

        .sx-points li {
            display: grid;
            gap: 6px 28px;
            padding-block: 26px;
            border-bottom: 1px solid var(--sx-edge);
        }

        .sx-points li::before {
            counter-increment: sx-point;
            content: '0' counter(sx-point);
            font-family: var(--sx-display);
            font-size: clamp(1.6rem, 3.4vw, 2.5rem);
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.03em;
            color: rgba(110, 136, 255, 0.34);
        }

        .sx-points h3 {
            font-family: var(--sx-display);
            font-size: clamp(1.05rem, 1.7vw, 1.3rem);
            font-weight: 600;
            line-height: 1.28;
            letter-spacing: -0.01em;
        }

        .sx-points p {
            color: var(--sx-muted);
            font-size: 0.94rem;
            line-height: 1.72;
        }

        .sx-points p b { color: var(--sx-text); font-weight: 600; }

        /* ── The honest limits: the densest block here ── */
        .sx-limits {
            display: grid;
            gap: 1px;
            margin-top: 32px;
            border: 1px solid var(--sx-edge);
            border-radius: 16px;
            background: var(--sx-edge);
            overflow: hidden;
            list-style: none;
        }

        .sx-limits li {
            display: grid;
            grid-template-columns: 20px 1fr;
            gap: 14px;
            padding: 18px 20px;
            background: #0d1219;
        }

        .sx-limits svg {
            width: 18px;
            height: 18px;
            margin-top: 3px;
            fill: none;
            stroke: var(--sx-faint);
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .sx-limits h3 {
            margin-bottom: 5px;
            font-family: var(--sx-display);
            font-size: 0.93rem;
            font-weight: 600;
            line-height: 1.35;
        }

        .sx-limits p {
            color: var(--sx-muted);
            font-size: 0.85rem;
            line-height: 1.62;
        }

        /* ── Closing: the emptiest block here ── */
        .sx-close { text-align: center; }
        .sx-close h2 { max-width: 26ch; margin-inline: auto; }
        .sx-close .sx-say { max-width: 52ch; margin-inline: auto; }

        .sx-close-cta {
            display: inline-flex;
            width: auto;
            margin-top: 30px;
            padding-inline: 34px;
        }

        .sx-close-note {
            margin-top: 24px;
            color: var(--sx-faint);
            font-size: 0.84rem;
            line-height: 1.6;
        }

        .sx-close-note a {
            color: var(--sx-primary-bright);
            text-decoration: none;
            border-bottom: 1px solid rgba(143, 163, 255, 0.35);
        }

        .sx-close-note a:hover { border-bottom-color: var(--sx-primary-bright); }

        .sx-close-note a:focus-visible {
            outline: 2px solid var(--sx-primary-bright);
            outline-offset: 3px;
            border-radius: 4px;
        }

        /* ── Footer ──────────────────────────────── */
        .sx-foot {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px 24px;
            padding: 28px 5%;
            border-top: 1px solid var(--sx-edge);
            background: rgba(0, 0, 0, 0.16);
            color: var(--sx-faint);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .sx-foot a { color: var(--sx-faint); text-decoration: none; transition: color .2s; }
        .sx-foot a:hover { color: var(--sx-primary-bright); }

        /* ── Arrival ─────────────────────────────── */

        /*
            One sequence on load rather than motion scattered through the page. Each piece
            of the hero arrives in the order somebody reads it, which walks the eye down to
            the one action above the fold instead of asking them to find it.

            `both` holds the first frame through the delay, so nothing flashes into place
            before its turn.
        */
        .sx-badge { animation: sx-rise .7s cubic-bezier(.2, .7, .2, 1) both .18s; }
        h1        { animation: sx-rise .7s cubic-bezier(.2, .7, .2, 1) both .28s; }
        .sx-lede  { animation: sx-rise .7s cubic-bezier(.2, .7, .2, 1) both .40s; }
        .sx-chips { animation: sx-rise .7s cubic-bezier(.2, .7, .2, 1) both .52s; }
        .sx-door  { animation: sx-rise .8s cubic-bezier(.2, .7, .2, 1) both .46s; }

        @keyframes sx-rise { from { opacity: 0; transform: translateY(18px); } }

        /*
            There is deliberately no reveal-on-scroll below the hero. It was built with the
            browser's own scroll-driven animation (`animation-timeline: view()`) and then
            taken back out on the rule that nothing a reader has to see may depend on an
            animation having run: a reader who opens the page at an anchor, reloads part-way
            down, or prints it can land outside the animation's range, and the failure there
            is a section at zero opacity rather than a section that arrives late.

            That was a precaution rather than a reproduced fault — the screenshot that
            prompted it turned out to be a headless-browser artifact, and the same blank
            frame appeared with the animation gone. It stays out anyway, because the upside
            was decoration and the downside is invisible content.

            The hero's arrival is a different case and stays: it runs on load, from the top
            of the page, every time.
        */

        /* Every focusable thing shows where the keyboard is. The browser's own ring is
           invisible against a dark ground. */
        .sx-wordmark:focus-visible,
        .sx-nav-links a:focus-visible,
        .sx-nav-btn:focus-visible,
        .sx-cta:focus-visible,
        .sx-foot a:focus-visible {
            outline: 2px solid var(--sx-primary-bright);
            outline-offset: 3px;
            border-radius: 8px;
        }

        /* Somebody who has asked their machine for less movement gets the page fully in
           place, rather than a page that arrives piece by piece or never arrives at all. */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

            .sx-badge, h1, .sx-lede, .sx-chips, .sx-door, .sx-strip i {
                animation: none;
                opacity: 1;
                transform: none;
            }

            .sx-cta, .sx-nav-btn, .sx-address, .sx-tile { transition: none; }
        }

        @media (min-width: 720px) {
            /* Two paragraphs as a block rather than one long ribbon. */
            .sx-say--two { grid-template-columns: 1fr 1fr; gap: 28px; }

            /* Number in its own column, heading and body beside it. Asymmetric on
               purpose: the heading column is narrow, the body column takes the rest. */
            .sx-points li { grid-template-columns: 4.5rem minmax(0, 23ch) 1fr; align-items: start; }
            .sx-points li::before { grid-row: span 1; }

            .sx-limits { grid-template-columns: 1fr 1fr; }
        }

        @media (min-width: 900px) {
            .sx-nav-links { display: flex; }

            /* Argument and action side by side, the argument taking the larger share. */
            .sx-hero { grid-template-columns: 1.12fr 0.88fr; align-items: center; gap: 56px; }

            /* Side by side and aligned to the top, not stretched, so the column of seven
               is visibly taller than the block of seven. That height difference is the
               whole point of the picture. */
            .sx-flows { grid-template-columns: 1fr 1fr; align-items: start; }

            /* Exits takes the full width above the other two, which sit beneath it as a
               pair. Two sizes rather than three equals. */
            .sx-tiles { grid-template-columns: 1fr 1fr; }
            .sx-tile--wide { grid-column: 1 / -1; }
        }

        @media (min-width: 1100px) {
            /*
                On a wide screen the drawn board sits beside the words rather than under
                them, so the largest tile is a split like the hero and not a tall stack.
            */
            .sx-tile--wide {
                display: grid;
                grid-template-columns: minmax(0, 27ch) 1fr;
                gap: 34px;
                align-items: center;
                padding: 30px;
            }

            .sx-tile--wide .sx-board { margin-top: 0; }
        }

        @media (max-width: 640px) {
            .sx-bar { padding: 14px 5%; }
            .sx-wordmark { font-size: 1.2rem; }
            .sx-wordmark svg { height: 26px; }
            .sx-door { padding: 22px 20px; }
            .sx-hero { padding-top: 6vh; padding-bottom: 8vh; }
            .sx-foot { justify-content: center; text-align: center; }

            /* Status stays beside the department. The department's own second line —
               the owner, and the reason if it is on hold — is what wraps. */
            .sx-board-rows li { align-items: start; }
            .sx-board-dept { min-width: 0; }

            /* The headline's smallest step is set here rather than in the clamp, because a
               floor low enough for a 390px screen is too small for a tablet. */
            h1 { font-size: clamp(2rem, 8.8vw, 2.7rem); }
        }
    </style>
</head>
<body>
    <nav class="sx-bar">
        <a href="/" class="sx-wordmark">
            <x-brand-mark />
            <span>Start<b>X</b></span>
        </a>

        <div class="sx-nav-links">
            <a href="#why">Why it exists</a>
            <a href="#runs">What it runs</a>
            <a href="#limits">Honest limits</a>
        </div>

        {{--
            One button, the same address on every page. On a client company's own address
            it is that company's sign-in page. On the bare domain there is no company yet,
            so the sign-in page sends the visitor back to the panel beside the headline,
            which asks which company they are — and that is why the button has no second
            version here.
        --}}
        <a href="{{ url('/admin/login') }}" class="sx-nav-btn">Sign in</a>
    </nav>

    <main>
        <section class="sx-hero sx-wrap sx-wrap--wide">
            <div class="sx-hero-copy">
                <span class="sx-badge"><i></i>Employee lifecycle platform</span>

                {{--
                    The space before the line break is not a typo to tidy away: without it the
                    heading is the single word "requestto" to a screen reader, and to any layout
                    where the break does not apply.
                --}}
                <h1>From the first <em>request</em> <br>to the final <em>clearance</em></h1>

                <p class="sx-lede">
                    One workspace for hiring requests, onboarding and exits — with
                    <b>an attributable trail</b> behind every approval and every rupee of a
                    final settlement.
                </p>

                <div class="sx-chips">
                    <span class="sx-chip">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <line x1="19" y1="8" x2="19" y2="14" />
                            <line x1="22" y1="11" x2="16" y2="11" />
                        </svg>
                        Onboarding
                    </span>
                    <span class="sx-chip">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M9 11l3 3L22 4" />
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
                        </svg>
                        Approvals
                    </span>
                    <span class="sx-chip">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                            <polyline points="14 2 14 8 20 8" />
                            <line x1="9" y1="15" x2="15" y2="15" />
                        </svg>
                        Clearances
                    </span>
                </div>
            </div>

            <section class="sx-door" id="sign-in">
                @isset($tenant)
                    {{-- Already at a company's own address, so there is nothing to ask. --}}
                    <h2>Sign in to {{ $tenant->name }}</h2>
                    <p>You are at your company's StartX address.</p>

                    <a href="{{ url('/admin/login') }}" class="sx-cta">
                        Sign in
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M5 12h14" />
                            <path d="m12 5 7 7-7 7" />
                        </svg>
                    </a>
                @else
                    <h2>Sign in to your company</h2>
                    <p>
                        Every company has its own StartX address, and that is where signing in
                        happens. Type yours and we will take you there. Your HR team has it if
                        you do not.
                    </p>

                    <form method="POST" action="{{ route('sign-in') }}">
                        @csrf

                        <label for="company" class="sx-label">Your company's address</label>

                        <div class="sx-address">
                            <input
                                id="company"
                                name="company"
                                value="{{ old('company') }}"
                                placeholder="yourcompany"
                                autocomplete="organization"
                                spellcheck="false"
                                autocapitalize="none"
                                required
                            >
                            <span>.{{ config('tenancy.central_domain') }}</span>
                        </div>

                        @error('company')
                            <p class="sx-error" role="alert">{{ $message }}</p>
                        @enderror

                        <button type="submit" class="sx-cta">
                            Continue
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 12h14" />
                                <path d="m12 5 7 7-7 7" />
                            </svg>
                        </button>
                    </form>
                @endisset
            </section>
        </section>

        {{--
            The statute, once, in one line. Deliberately the shortest section on the page:
            it is the change of pace directly under the tallest one.
        --}}
        <div class="sx-strip">
            <div class="sx-wrap sx-wrap--wide">
                <i></i>
                <span><b>Two working days</b> to pay an employee's final wages</span>
                <s></s>
                <span>Section 17(2), Code on Wages 2019</span>
                <s></s>
                <span>In force since 21 November 2025</span>
            </div>
        </div>

        {{--
            Why the product exists. Stated as what the law requires and what the arithmetic
            of a queue makes impossible — not as a threat, because `market-position.md` is
            explicit that enforcement is softer than a compliance pitch wants and that
            "you will be fined" is not a true sentence.
        --}}
        <section class="sx-band sx-band--tall sx-band--lit" id="why">
            <div class="sx-wrap sx-wrap--wide">
                <span class="sx-eyebrow">Why this exists</span>

                <h2>Two working days is not enough time to run clearances <em>in a queue</em></h2>

                <div class="sx-say sx-say--two">
                    <p>
                        The long-standing practice of thirty to forty-five days is no longer lawful
                        on paper, and it is indefensible the moment a settlement is contested.
                    </p>

                    <p>
                        Most exit modules were built for the old world: one department signs, then
                        the next. Under a two-day rule <b>that shape cannot work</b>, however hard
                        anybody chases it.
                    </p>
                </div>

                <div class="sx-flows">
                    <div class="sx-flow">
                        <div class="sx-flow-head">
                            <h3>Clearances in a queue</h3>
                            <span class="sx-verdict sx-verdict--slow">A week at best</span>
                        </div>

                        <ul class="sx-steps sx-steps--queue">
                            <li>Manager <em>day 1</em></li>
                            <li>Business head <em>day 1</em></li>
                            <li>Director <em>day 2</em></li>
                        </ul>

                        <p class="sx-deadline">Two working days end here</p>

                        <ul class="sx-steps sx-steps--queue sx-past">
                            <li>IT <em>day 3</em></li>
                            <li>Admin <em>day 4</em></li>
                            <li>Finance <em>day 5</em></li>
                            <li>HR <em>day 5</em></li>
                        </ul>

                        <p class="sx-flow-note">
                            Four of the seven have not started by the time the wages are due.
                        </p>
                    </div>

                    <div class="sx-flow sx-flow--ours">
                        <div class="sx-flow-head">
                            <h3>Clearances all at once, from the resignation</h3>
                            <span class="sx-verdict sx-verdict--fast">Fits two working days</span>
                        </div>

                        <p class="sx-atonce-day">All seven, day 1</p>

                        <ul class="sx-steps sx-steps--atonce">
                            <li>Manager</li>
                            <li>Business head</li>
                            <li>Director</li>
                            <li>IT</li>
                            <li>Admin</li>
                            <li>Finance</li>
                            <li>HR</li>
                        </ul>

                        <p class="sx-deadline">Two working days end here</p>

                        <p class="sx-flow-note">
                            All seven start the moment somebody resigns, and the slowest one sets
                            the date rather than the sum of all seven.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{--
            One thought, at the largest type below the hero, holding nothing else. The two
            halves are both defensible: `market-position.md` records the thirty-to-forty-five-day
            practice and the two-working-day requirement, and neither half claims a penalty.
        --}}
        <section class="sx-quote">
            <div class="sx-wrap">
                <p class="sx-quote-line">
                    Thirty to forty-five days was the habit.
                    <em>Two working days is <b>the rule</b>.</em>
                </p>

                <p class="sx-quote-src">Section 17(2), Code on Wages 2019 — in force since 21 November 2025</p>
            </div>
        </section>

        {{-- What the product actually runs. The three process families from `overview.md`. --}}
        <section class="sx-band" id="runs">
            <div class="sx-wrap sx-wrap--wide">
                <span class="sx-eyebrow">What it runs</span>

                <h2>Three processes, one engine underneath</h2>

                <div class="sx-say">
                    <p>
                        Ordered steps with parallel groups, conditions, send-back and hold. The same
                        engine runs all three, which is why a change to one is
                        <b>configuration rather than a new screen</b>.
                    </p>
                </div>

                <div class="sx-tiles">
                    <article class="sx-tile sx-tile--wide">
                        <div>
                            <div class="sx-tile-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <polyline points="12 7 12 12 16 14" />
                                </svg>
                            </div>

                            <h3>Exits and clearances</h3>

                            <p>
                                Every department at once, each with its own hold reasons and its own
                                money to declare, <b>chased against the deadline</b> and rolling up
                                into one settlement statement.
                            </p>
                        </div>

                        <div>
                            <div class="sx-board" role="img" aria-label="A clearance board: one leaver, seven departments running at the same time, each with the person who owns it and where it stands, above a running total for the settlement and a countdown to the deadline.">
                                <div class="sx-board-top">
                                    <div class="sx-board-who">
                                        Rohit Verma — Warehouse Operations
                                        <small>Resigned 04 Sep · last day 12 Sep</small>
                                    </div>

                                    <span class="sx-board-clock"><i></i>1 working day left</span>
                                </div>

                                <ul class="sx-board-rows">
                                    <li>
                                        <span class="sx-board-dept"><b>Manager</b> <span>Anjali Nair</span></span>
                                        <span class="sx-pill sx-pill--done">Cleared</span>
                                    </li>
                                    <li>
                                        <span class="sx-board-dept"><b>Business head</b> <span>Rakesh Menon</span></span>
                                        <span class="sx-pill sx-pill--done">Cleared</span>
                                    </li>
                                    <li>
                                        <span class="sx-board-dept"><b>Director</b> <span>Deepak Iyer</span></span>
                                        <span class="sx-pill sx-pill--done">Cleared</span>
                                    </li>
                                    <li>
                                        <span class="sx-board-dept"><b>IT</b> <span>Priya Rao · laptop not returned</span></span>
                                        <span class="sx-pill sx-pill--hold">On hold</span>
                                    </li>
                                    <li>
                                        <span class="sx-board-dept"><b>Admin</b> <span>Chandni Bose</span></span>
                                        <span class="sx-pill sx-pill--done">Cleared</span>
                                    </li>
                                    <li>
                                        <span class="sx-board-dept"><b>Finance</b> <span>Rakesh Menon · 2 recovery lines</span></span>
                                        <span class="sx-pill sx-pill--done">Declared</span>
                                    </li>
                                    <li>
                                        <span class="sx-board-dept"><b>HR</b> <span>Anjali Nair</span></span>
                                        <span class="sx-pill sx-pill--open">Waiting on IT</span>
                                    </li>
                                </ul>

                                <div class="sx-board-foot">
                                    <span>Settlement so far — <b>6 of 7 declared</b></span>
                                    <span>Statement goes out when IT clears</span>
                                </div>
                            </div>

                            <p class="sx-drawn">
                                A drawing of that board, not a screenshot. It is the shape the exit
                                screen has; we will put the real screen here once it is built.
                            </p>
                        </div>
                    </article>

                    <article class="sx-tile">
                        <div class="sx-tile-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="12" y1="18" x2="12" y2="12" />
                                <line x1="9" y1="15" x2="15" y2="15" />
                            </svg>
                        </div>

                        <h3>Hiring requests</h3>

                        <p>
                            A manager asks for a role. It goes up the approvals you defined, on
                            <b>the conditions you set</b> — a salary above a threshold takes a
                            different route from one below it.
                        </p>
                    </article>

                    <article class="sx-tile">
                        <div class="sx-tile-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <line x1="19" y1="8" x2="19" y2="14" />
                                <line x1="22" y1="11" x2="16" y2="11" />
                            </svg>
                        </div>

                        <h3>Onboarding</h3>

                        <p>
                            Offer out, documents back, assets issued, statutory identifiers
                            captured. The candidate fills their own part on
                            <b>a link, with no account</b> to create first.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        {{-- The differentiation from `market-position.md`, in the order it argues them. No
             cards here on purpose: numbered rows on hairlines, so this section does not
             look like the one above it. --}}
        <section class="sx-band sx-band--tint">
            <div class="sx-wrap">
                <span class="sx-eyebrow">What is different</span>

                <h2>Built to be configured, and to be <em>answerable</em> afterwards</h2>

                <ol class="sx-points">
                    <li>
                        <h3>Your process is data, not our code</h3>
                        <p>
                            Steps, the form on each one, the conditions that route them and the
                            letters they produce are yours to change. A new company is
                            <b>a configuration, not a fork</b> — which is why this does not need
                            the three-to-six-month implementation the market is used to.
                        </p>
                    </li>

                    <li>
                        <h3>Every approval has a name on it</h3>
                        <p>
                            Who decided, when, and on what they could see at the time — kept as
                            <b>a record that is added to and never rewritten</b>. A contested
                            exit is answered from the file rather than from memory.
                        </p>
                    </li>

                    <li>
                        <h3>Every rupee is a line somebody put there</h3>
                        <p>
                            Recoveries and payables, each with the person who added it and the
                            reason they gave, adding up to <b>the statement the leaver signs</b>
                            against a deadline the product is watching.
                        </p>
                    </li>

                    <li>
                        <h3>We are not your payroll</h3>
                        <p>
                            We produce the settlement figure and <b>hand it to whoever runs your
                            payroll</b>. Building payroll would mean a second product and a
                            compliance surface nobody needs us to take on.
                        </p>
                    </li>
                </ol>
            </div>
        </section>

        {{--
            The limits, said out loud, and the densest block on the page. This section
            stands where a normal landing page puts customer logos and testimonials: we have
            neither, and a product selling an audit trail cannot invent proof. What we can
            do is be the vendor that says what it does not do before month three does.
        --}}
        <section class="sx-band sx-band--tight" id="limits">
            <div class="sx-wrap">
                <span class="sx-eyebrow">Honest limits</span>

                <h2>What StartX deliberately does not do</h2>

                <div class="sx-say">
                    <p>
                        Better said here than found out in month three. Each of these is a decision,
                        not a gap waiting to be filled.
                    </p>
                </div>

                <ul class="sx-limits">
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <line x1="8" y1="12" x2="16" y2="12" />
                        </svg>

                        <div>
                            <h3>Payroll, and the statutory calculations inside it</h3>
                            <p>
                                Provident fund, insurance, professional tax and the gratuity
                                figure stay with your payroll. We track that gratuity is owed and
                                that it has its own thirty-day clock; your payroll works out the
                                amount.
                            </p>
                        </div>
                    </li>

                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <line x1="8" y1="12" x2="16" y2="12" />
                        </svg>

                        <div>
                            <h3>Attendance, leave, performance and recruitment</h3>
                            <p>
                                Not our problem to solve, and we will not pretend otherwise. Where
                                a process needs something from one of those systems, we integrate
                                with it.
                            </p>
                        </div>
                    </li>

                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <line x1="8" y1="12" x2="16" y2="12" />
                        </svg>

                        <div>
                            <h3>Native mobile apps</h3>
                            <p>
                                The pages work on a phone. An approval on the way to a meeting
                                does not need an app store in between.
                            </p>
                        </div>
                    </li>

                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <line x1="8" y1="12" x2="16" y2="12" />
                        </svg>

                        <div>
                            <h3>Lifting the history out of your old system</h3>
                            <p>
                                StartX starts clean. Keeping the old records where they are, and
                                readable, is cheaper and safer than translating years of somebody
                                else's schema.
                            </p>
                        </div>
                    </li>
                </ul>
            </div>
        </section>

        <section class="sx-band sx-band--tall sx-band--tint sx-close">
            <div class="sx-wrap">
                <span class="sx-eyebrow">Summerhill Technologies</span>

                <h2>Want to see it against your own exit process?</h2>

                <div class="sx-say">
                    <p>
                        Bring the clearances you actually run, with the departments and the approvals
                        in the order they happen today. That conversation is more useful than a demo
                        of ours.
                    </p>
                </div>

                @if ($contact = config('startx.contact_email'))
                    <a href="mailto:{{ $contact }}?subject=StartX" class="sx-cta sx-close-cta">
                        Talk to us
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="2" y="4" width="20" height="16" rx="2" />
                            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                        </svg>
                    </a>
                @endif

                <p class="sx-close-note">
                    Already using StartX? Signing in happens at your own company's address —
                    <a href="#sign-in">the panel at the top of this page</a>
                    will take you there.
                </p>
            </div>
        </section>
    </main>

    <footer class="sx-foot">
        <span>&copy; {{ date('Y') }} Summerhill Technologies</span>
        <a href="{{ url('/admin/login') }}">Sign in</a>
    </footer>
</body>
</html>
