{{--
    The page every letter is drawn on, for the PDF engine and nothing else.

    $body is the client's wording already cleaned to bare structure and text, which is the
    one thing here not escaped. $letterhead is the image's own bytes as a data address, so
    the engine opens no file for it. DejaVu Sans is the engine's own font, and it has the
    rupee sign.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 1.5cm 2cm 2cm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; line-height: 1.5; color: #111; }
        .letterhead { width: 100%; margin-bottom: 0.8cm; }
        h1, h2, h3, h4, h5, h6 { line-height: 1.25; margin: 0.6em 0 0.3em; }
        p { margin: 0 0 0.7em; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 0.7em; }
        th, td { border: 1px solid #999; padding: 3pt 6pt; vertical-align: top; text-align: left; }
    </style>
</head>
<body>
    @if ($letterhead !== null)
        <img class="letterhead" src="{{ $letterhead }}" alt="">
    @endif

    {!! $body !!}
</body>
</html>
