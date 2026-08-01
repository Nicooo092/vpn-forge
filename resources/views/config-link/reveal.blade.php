@extends('config-link.layout')

@section('title', 'Your VPN configuration')

@section('content')
    <h1>Save your configuration</h1>
    <p class="muted">
        For <strong>{{ $userName }}</strong> on <strong>{{ $serviceName }}</strong>.
        This link is now used up -- keep the file safe.
    </p>

    @if ($qrDataUri)
        <div class="qr-plate">
            <img src="{{ $qrDataUri }}" alt="Configuration QR code">
        </div>
        <p class="muted" style="text-align:center; margin-top:10px;">
            In the WireGuard app, tap <strong>+</strong> &rarr; <strong>Scan from QR code</strong>.
        </p>
        <hr class="divider">
    @endif

    <div class="stack">
        <a class="btn" href="{{ $downloadUri }}" download="{{ $filename }}">Download {{ $filename }}</a>

        <textarea class="config" readonly id="config-text" aria-label="Configuration file contents">{{ $contents }}</textarea>

        <button type="button" class="btn btn-secondary" id="copy-btn">Copy to clipboard</button>
    </div>

    <p class="muted" style="margin-top:16px; font-size:13px;">
        This file (and the QR code) carries a private key. Anyone who has it can connect as you.
    </p>

    <script>
        (function () {
            var btn = document.getElementById('copy-btn');
            var text = document.getElementById('config-text');
            btn.addEventListener('click', function () {
                text.select();
                var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy to clipboard'; }, 1500); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text.value).then(done, function () { document.execCommand('copy'); done(); });
                } else {
                    document.execCommand('copy');
                    done();
                }
            });
        })();
    </script>
@endsection
