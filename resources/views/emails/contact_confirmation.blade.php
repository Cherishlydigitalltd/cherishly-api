@extends('emails.layouts.base')

@section('subject')We received your message @endsection

@section('content')
    <p class="email-greeting">Thanks for reaching out! 👋</p>

    <p class="email-text">
        Hi <strong>{{ $data['first_name'] }}</strong>, we've received your message
        and our team will get back to you within <strong>24 hours</strong>.
    </p>

    <div class="warning-box">
        <p>
            💡 <strong>Need urgent help?</strong> Email us directly at
            <a href="mailto:support@cherishlyng.com" style="color: #E8604A;">support@cherishlyng.com</a>
        </p>
    </div>

    <hr class="email-divider" />

    <p class="email-text" style="font-size: 13px; color: #9B7B7B;">
        This is a confirmation that we received your message. Please do not reply to this email.
    </p>
@endsection