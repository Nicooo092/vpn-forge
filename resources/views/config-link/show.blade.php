@extends('config-link.layout')

@section('title', __('Get your VPN configuration'))

@section('content')
    <h1>{{ __('Your VPN configuration is ready') }}</h1>
    <p class="muted">
        {!! __('For :user on :service.', ['user' => '<strong>'.e($userName).'</strong>', 'service' => '<strong>'.e($serviceName).'</strong>']) !!}
    </p>

    <p class="muted">
        @if ($expiresAt)
            {{ __('This link works once, and only until :time UTC.', ['time' => $expiresAt->toDayDateTimeString()]) }}
        @else
            {{ __('This link works once. Open it only when you are ready to save the config.') }}
        @endif
    </p>

    {{-- The reveal is a POST so an automated link preview (chat apps, crawlers)
         can't spend the single use before the person clicks. --}}
    <form method="POST" action="{{ route('config-link.reveal', ['token' => $token]) }}" class="stack">
        @csrf
        <button type="submit" class="btn">{{ __('Show my configuration') }}</button>
    </form>
@endsection
