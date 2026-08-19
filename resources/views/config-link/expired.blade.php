@extends('config-link.layout')

@section('title', __('Link unavailable'))

@section('content')
    <div class="icon">&#128274;</div>
    <h1>{{ __('Link unavailable') }}</h1>
    <p class="muted">{{ $reason }}</p>
    <p class="muted">{{ __('Ask whoever sent it to generate a new one.') }}</p>
@endsection
