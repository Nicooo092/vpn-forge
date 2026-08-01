@extends('config-link.layout')

@section('title', 'Link unavailable')

@section('content')
    <div class="icon">&#128274;</div>
    <h1>Link unavailable</h1>
    <p class="muted">{{ $reason }}</p>
    <p class="muted">Ask whoever sent it to generate a new one.</p>
@endsection
