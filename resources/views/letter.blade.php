{{--
    The page every letter is drawn on, for the PDF engine and nothing else.

    $body is the client's wording already cleaned to bare structure and text, which is the
    one thing here not escaped. Each picture is the image's own bytes as a data address, so
    the engine opens no file for it. DejaVu Sans is the engine's own font, and it has the
    rupee sign.

    The footer strip comes first because the engine repeats a fixed element only on the
    pages after the point it appears. The bottom margin makes room for it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 1.5cm 2cm {{ $footer !== null ? '3.6cm' : '2cm' }}; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; line-height: 1.5; color: #111; }
        .letterhead { width: 100%; margin-bottom: 0.8cm; }
        .footer { position: fixed; left: 0; right: 0; bottom: -2.8cm; height: 2.4cm; text-align: center; }
        .footer img { max-width: 100%; max-height: 2.4cm; }
        .signed { margin-top: 0.4cm; page-break-inside: avoid; }
        .signed img { max-width: 6cm; max-height: 2.2cm; }
        h1, h2, h3, h4, h5, h6 { line-height: 1.25; margin: 0.6em 0 0.3em; }
        p { margin: 0 0 0.7em; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 0.7em; }
        th, td { border: 1px solid #999; padding: 3pt 6pt; vertical-align: top; text-align: left; }
    </style>
</head>
<body>
    @if ($footer !== null)
        <div class="footer"><img src="{{ $footer }}" alt=""></div>
    @endif

    @if ($letterhead !== null)
        <img class="letterhead" src="{{ $letterhead }}" alt="">
    @endif

    {!! $body !!}

    @if ($signature !== null || $signedBy !== '' || $signedAs !== '')
        <div class="signed">
            @if ($signature !== null)
                <img src="{{ $signature }}" alt=""><br>
            @endif
            @if ($signedBy !== '')
                <strong>{{ $signedBy }}</strong><br>
            @endif
            {{ $signedAs }}
        </div>
    @endif
</body>
</html>
