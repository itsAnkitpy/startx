{{--
    The page a leaver opens from the link to the documents issued on her exit (module 09 step 4).

    She has no account here, so everything she needs is on this page: which company issued
    them, each document to open, and the answers each one takes. Like the step's link page it
    needs no front-end build.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your documents — {{ $case->tenant->name }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <meta name="theme-color" content="#0a0e14">

    {{-- The address itself is the permission: never indexed, never sent on as a referrer. --}}
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
            width: min(38rem, 100%);
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

        .sx-doc {
            margin-top: 22px;
            padding: 18px 20px;
            border: 1px solid var(--sx-hair);
            border-radius: 16px;
            background: rgba(0, 0, 0, 0.18);
        }

        .sx-doc a {
            color: var(--sx-primary-bright);
            font-family: var(--sx-display);
            font-size: 1.02rem;
            font-weight: 600;
            text-decoration: none;
        }

        .sx-doc a:hover { text-decoration: underline; }

        .sx-answered { margin-top: 10px; color: var(--sx-text); }

        label { display: block; margin: 14px 0 8px; font-size: 0.86rem; color: var(--sx-text); }

        textarea {
            width: 100%;
            min-height: 5.5rem;
            padding: 12px 14px;
            border: 1px solid var(--sx-hair);
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.25);
            color: var(--sx-text);
            font: inherit;
            resize: vertical;
        }

        .sx-answers { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }

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

        .sx-refused {
            margin-top: 14px;
            padding: 12px 14px;
            border: 1px solid rgba(255, 138, 138, 0.45);
            border-radius: 12px;
            background: rgba(255, 96, 96, 0.12);
            color: #ffc9c9;
            white-space: pre-line;
        }
    </style>
</head>
<body>
    <main>
        <span class="sx-wordmark">
            <x-brand-mark />
            Start<b>X</b>
        </span>

        <h1>Your documents from {{ $case->tenant->name }}</h1>

        <p>
            {{ $case->tenant->name }} has issued these to you as part of your exit. Open each one to
            read or download it, then answer it here.
        </p>

        @foreach ($documents as $document)
            <section class="sx-doc">
                <a href="{{ route('document-link.file', [$token, $document]) }}" target="_blank" rel="noreferrer">{{ $document->name() }}</a>
                <p>Issued {{ $document->created_at->timezone($case->tenant->timezone)->format('j F Y') }}</p>

                {{-- What was refused about an answer to this document, in the words the rule
                     wrote, beside it rather than on a page of its own: the link still works. --}}
                @if (($refusedFor ?? null) === $document->getKey())
                    <p class="sx-refused">{{ $refused }}</p>
                @endif

                @if ($document->answer !== null)
                    <p class="sx-answered">
                        {{ $document->answerReads($case->tenant->timezone) }}.
                        @if ($document->reason !== null)
                            Your reason: {{ $document->reason }}
                        @endif
                    </p>
                @else
                    <form method="POST" action="{{ route('document-link.answer', [$token, $document]) }}">
                        @csrf

                        @if (in_array('refused', $document->answersOffered(), true))
                            <p>
                                Accepting it means you agree with the amounts in it. If you do not,
                                refuse it and say why, and {{ $case->tenant->name }} is told at once.
                            </p>

                            <label for="reason-{{ $document->getKey() }}">
                                If you are refusing it, why (at least {{ \App\Process\DocumentLinks::ReasonAtLeast }} characters)
                            </label>
                            <textarea
                                id="reason-{{ $document->getKey() }}"
                                name="reason"
                                maxlength="{{ \App\Process\DocumentLinks::ReasonAtMost }}"
                            >{{ ($refusedFor ?? null) === $document->getKey() ? ($reason ?? '') : '' }}</textarea>
                        @endif

                        <div class="sx-answers">
                            @foreach ($document->answersOffered() as $offered)
                                <button
                                    type="submit"
                                    name="answer"
                                    value="{{ $offered }}"
                                    @class(['sx-no' => $offered === 'refused'])
                                >{{ \App\Models\IssuedDocument::Answers[$offered][0] }}</button>
                            @endforeach
                        </div>
                    </form>
                @endif
            </section>
        @endforeach

        <p class="sx-note">
            Each document is answered once. The link works for {{ $lastsHours }} hours from when it
            was sent and can be opened {{ $opens }} times; opening a document or answering one does
            not use an open up. If it stops working it will offer you a new one.
        </p>
    </main>
</body>
</html>
