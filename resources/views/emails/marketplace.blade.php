@extends('emails.layouts.base')

@section('subject')
    {{ $subject }}
@endsection

@section('content')

    <p class="email-greeting">Hi {{ $name }},</p>

    @foreach ($lines as $line)
        <p class="email-text">{{ $line }}</p>
    @endforeach

    @if ($button && $url)
        <div style="text-align: center; margin: 28px 0;">
            <a href="{{ $url }}" class="email-btn">{{ $button }}</a>
        </div>
    @endif

@endsection
