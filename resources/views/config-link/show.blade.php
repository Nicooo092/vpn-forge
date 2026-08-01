@extends('config-link.layout')

@section('title', 'Get your VPN configuration')

@section('content')
    <h1>Your VPN configuration is ready</h1>
    <p class="muted">
        For <strong>{{ $userName }}</strong> on <strong>{{ $serviceName }}</strong>.
    </p>

    <p class="muted">
        @if ($expiresAt)
            This link works once, and only until {{ $expiresAt->toDayDateTimeString() }} UTC.
        @else
            This link works once. Open it only when you are ready to save the config.
        @endif
    </p>

    {{-- The reveal is a POST so an automated link preview (chat apps, crawlers)
         can't spend the single use before the person clicks. --}}
    <form method="POST" action="{{ route('config-link.reveal', ['token' => $token]) }}" class="stack">
        @csrf
        <button type="submit" class="btn">Show my configuration</button>
    </form>
@endsection
