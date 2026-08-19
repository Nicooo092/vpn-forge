@extends('config-link.layout')

@section('title', __('Your VPN configuration'))

@section('content')
    <h1>{{ __('Save your configuration') }}</h1>
    <p class="muted">
        {!! __('For :user on :service.', ['user' => '<strong>'.e($userName).'</strong>', 'service' => '<strong>'.e($serviceName).'</strong>']) !!}
        {{ __('This link is now used up -- keep the file safe.') }}
    </p>

    @if ($isWireGuard)
        @if ($qrDataUri)
            <div class="qr-plate">
                <img src="{{ $qrDataUri }}" alt="{{ __('Configuration QR code') }}">
            </div>
        @endif
        <p class="muted" style="text-align:center; margin-top:10px;">
            {{ __('First install the WireGuard app on your device.') }}
            @if ($qrDataUri)
                {!! __('In the WireGuard app, tap <strong>+</strong> then <strong>Scan from QR code</strong>, or import the file below.') !!}
            @else
                {{ __('In the WireGuard app, import the file below.') }}
            @endif
        </p>
        <hr class="divider">
    @else
        <p class="muted" style="margin-top:10px;">
            {{ __('First install the OpenVPN Connect app on your device.') }}
            {{ __('Then download the file below and open it in OpenVPN Connect to import your profile.') }}
        </p>
        <hr class="divider">
    @endif

    <div class="stack">
        <a class="btn" href="{{ $downloadUri }}" download="{{ $filename }}">{{ __('Download :filename', ['filename' => $filename]) }}</a>

        <textarea class="config" readonly id="config-text" aria-label="{{ __('Configuration file contents') }}">{{ $contents }}</textarea>

        <button type="button" class="btn btn-secondary" id="copy-btn">{{ __('Copy to clipboard') }}</button>
    </div>

    <p class="muted" style="margin-top:16px; font-size:13px;">
        @if ($isWireGuard)
            {{ __('This file (and the QR code) carries a private key. Anyone who has it can connect as you.') }}
        @else
            {{ __('This file carries a private key. Anyone who has it can connect as you.') }}
        @endif
    </p>

    <script>
        (function () {
            var btn = document.getElementById('copy-btn');
            var text = document.getElementById('config-text');
            var copyLabel = @json(__('Copy to clipboard'));
            var copiedLabel = @json(__('Copied'));
            btn.addEventListener('click', function () {
                text.select();
                var done = function () { btn.textContent = copiedLabel; setTimeout(function () { btn.textContent = copyLabel; }, 1500); };
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
