{{--
    The page a visitor gets when the address names no client company we have, or one
    whose access is switched off. Deliberately plain: it is styled with the sign-in
    page, not before it, and it must render with no built assets so a wrong address
    never depends on a front-end build.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0a0e14; color: #f8fafc;
               font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 30rem; padding: 2rem; text-align: center; }
        h1 { margin: 0 0 0.75rem; font-size: 1.5rem; font-weight: 600; }
        p { margin: 0; color: #94a3b8; line-height: 1.6; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
    </main>
</body>
</html>
