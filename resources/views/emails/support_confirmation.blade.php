@extends('emails.layouts.base')

@section('subject')
    We received your request
@endsection

@section('content')

    <p class="email-greeting">We've got your message! 👋</p>

    <p class="email-text">
        Hi <strong>{{ $user->first_name }}</strong>, thank you for reaching out to Cherishly support.
        We've received your request and our team will get back to you within
        <strong>24 hours</strong>.
    </p>

    <div class="warning-box">
        <p>
            💡 <strong>In the meantime</strong>, you can check our
            <a href="https://cherishlyng.com/help" style="color: #E8604A;">Help Center</a>
            for quick answers to common questions.
        </p>
    </div>

    <hr class="email-divider" />

    <p class="email-text" style="font-size: 13px; color: #9B7B7B;">
        Need urgent help? Email us directly at
        <a href="mailto:support@cherishlyng.com" style="color: #E8604A;">support@cherishlyng.com</a>
    </p>

@endsection