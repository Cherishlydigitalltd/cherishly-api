@extends('emails.layouts.base')

@section('subject')
    New donation received!
@endsection

@section('content')

    <p class="email-greeting">You received a donation! 💰</p>

    <p class="email-text">
        Hi <strong>{{ $owner->first_name }}</strong>, great news!
        Someone just donated to your monetary gift collection on Cherishly.
    </p>

    {{-- Donation Details --}}
    <div class="otp-box">
        <p class="otp-label">Donation Details</p>
        <p class="otp-code">₦{{ number_format($amount, 2) }}</p>
        <p class="otp-expiry">
            from <strong>{{ $donorName }}</strong>
        </p>
    </div>

    <p class="email-text">
        <strong>Collection:</strong> {{ $giftTitle }}
    </p>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $dashboardLink }}" class="email-btn">VIEW COLLECTION</a>
    </div>

    <div class="warning-box">
        <p>
            💡 <strong>Did you know?</strong>
            All donations are automatically credited to your Cherishly wallet.
            You can withdraw anytime from your dashboard.
        </p>
    </div>

    <hr class="email-divider" />

    <p class="email-text" style="font-size: 13px; color: #9B7B7B;">
        Questions? <a href="https://cherishlyng.com/contact" style="color: #E8604A;">Contact our support team</a>.
    </p>

@endsection