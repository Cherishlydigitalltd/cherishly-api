@extends('emails.layouts.base')

@section('subject')
    Support Request
@endsection

@section('content')

    <p class="email-greeting">New Support Request 🎫</p>

    <p class="email-text">
        A user has submitted a support request on Cherishly.
    </p>

    <div class="warning-box">
        <p><strong>From:</strong> {{ $user->full_name }}</p>
        <p><strong>Email:</strong> {{ $user->email }}</p>
        <p><strong>Phone:</strong> {{ $user->phone ?? 'Not provided' }}</p>
    </div>

    <div class="otp-box" style="text-align: left;">
        <p class="otp-label">Message</p>
        <p class="email-text" style="margin: 0;">{{ $message }}</p>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="mailto:{{ $user->email }}" class="email-btn">REPLY TO USER</a>
    </div>

@endsection