@extends('config-link.layout')

@section('title', 'Configuration not ready yet')

@section('content')
    <div class="icon">&#9203;</div>
    <h1>Almost ready</h1>
    <p class="muted">
        The configuration for <strong>{{ $userName }}</strong> is still being generated on the server.
        This link has not been used -- refresh in a moment to try again.
    </p>
    <p class="muted">
        <a href="{{ route('config-link.show', ['token' => $token]) }}">Try again</a>
    </p>
@endsection
