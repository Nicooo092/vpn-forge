@extends('config-link.layout')

@section('title', __('Configuration not ready yet'))

@section('content')
    <div class="icon">&#9203;</div>
    <h1>{{ __('Almost ready') }}</h1>
    <p class="muted">
        {!! __('The configuration for :user is still being generated on the server. This link has not been used -- refresh in a moment to try again.', ['user' => '<strong>'.e($userName).'</strong>']) !!}
    </p>
    <p class="muted">
        <a href="{{ route('config-link.show', ['token' => $token]) }}">{{ __('Try again') }}</a>
    </p>
@endsection
