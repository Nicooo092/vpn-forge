<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Both belt and braces for keeping the token out of anywhere it could
         leak: no referer on any navigation, and no indexing. --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'VPN configuration')</title>
    {{-- Everything is inline. The page can hold a private key, so it must not
         pull a single external resource whose request would carry the token in
         its Referer header. --}}
    <style>
        :root {
            --bg: #f3f4f6;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #111827;
            --muted: #6b7280;
            --accent: #2563eb;
            --accent-text: #ffffff;
            --code-bg: #f9fafb;
            --danger: #b91c1c;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0f19;
                --card: #111827;
                --border: #1f2937;
                --text: #f3f4f6;
                --muted: #9ca3af;
                --accent: #3b82f6;
                --accent-text: #ffffff;
                --code-bg: #0b0f19;
                --danger: #f87171;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.5;
        }
        .card {
            width: 100%;
            max-width: 30rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
        }
        .brand {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
            color: var(--muted);
            margin: 0 0 18px;
        }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p { margin: 0 0 14px; }
        .muted { color: var(--muted); font-size: 14px; }
        .btn {
            display: inline-block;
            width: 100%;
            text-align: center;
            padding: 11px 16px;
            border-radius: 8px;
            border: 1px solid transparent;
            background: var(--accent);
            color: var(--accent-text);
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-secondary {
            background: transparent;
            color: var(--text);
            border-color: var(--border);
        }
        button.btn { font-family: inherit; }
        .stack > * + * { margin-top: 12px; }
        code, pre, textarea.config {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        textarea.config {
            width: 100%;
            height: 150px;
            resize: vertical;
            background: var(--code-bg);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px;
            font-size: 12px;
            white-space: pre;
            overflow: auto;
        }
        .qr-plate {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px;
            display: flex;
            justify-content: center;
        }
        .qr-plate img { width: 100%; max-width: 260px; height: auto; }
        .divider { height: 1px; background: var(--border); margin: 20px 0; border: 0; }
        .icon { font-size: 30px; line-height: 1; margin-bottom: 8px; }
    </style>
</head>
<body>
    <main class="card">
        <p class="brand">vpn-forge</p>
        @yield('content')
    </main>
</body>
</html>
