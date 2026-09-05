@extends('emails.layouts.base')

@section('subject')Contact Form Submission @endsection

@section('content')
    <p class="email-greeting">New Contact Form Submission 📬</p>

    <div class="warning-box">
        <p><strong>Name:</strong> {{ $data['first_name'] }} {{ $data['last_name'] }}</p>
        <p><strong>Email:</strong> {{ $data['email'] }}</p>
        <p><strong>Phone:</strong> {{ $data['phone'] ?? 'Not provided' }}</p>
        <p><strong>Source:</strong> {{ $data['source'] ?? 'Not provided' }}</p>
    </div>

    <div class="otp-box" style="text-align: left;">
        <p class="otp-label">Message</p>
        <p class="email-text" style="margin: 0;">{{ $data['question'] }}</p>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="mailto:{{ $data['email'] }}" class="email-btn">REPLY TO USER</a>
    </div>
@endsection