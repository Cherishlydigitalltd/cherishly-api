@extends('emails.layouts.base')

@section('subject')
    New contribution received!
@endsection

@section('content')

    <p class="email-greeting">You received a gift contribution! 🎁</p>

    <p class="email-text">
        Hi <strong>{{ $owner->first_name }}</strong>, great news!
        Someone just contributed to your gift registry on Cherishly.
    </p>

    {{-- Contribution Details --}}
    <div class="otp-box">
        <p class="otp-label">Contribution Details</p>
        <p class="otp-code">₦{{ number_format($amount, 2) }}</p>
        <p class="otp-expiry">
            from <strong>{{ $donorName }}</strong>
        </p>
    </div>

    <p class="email-text">
        <strong>Gift:</strong> {{ $giftName }}<br />
        <strong>Registry:</strong> {{ $registryName }}
    </p>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $dashboardLink }}" class="email-btn">VIEW REGISTRY</a>
    </div>

    <div class="warning-box">
        <p>
            💡 <strong>Did you know?</strong>
            All contributions are automatically credited to your Cherishly wallet.
            You can withdraw anytime from your dashboard.
        </p>
    </div>

    <hr class="email-divider" />

    <p class="email-text" style="font-size: 13px; color: #9B7B7B;">
        Questions? <a href="https://cherishlyng.com/contact" style="color: #E8604A;">Contact our support team</a>.
    </p>

@endsection