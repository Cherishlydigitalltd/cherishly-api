@extends('emails.layouts.base')

@section('subject')
  @if($type === 'email_verification')
    Verify your Cherishly email
  @elseif($type === 'password_reset')
    Reset your Cherishly password
  @else
    Cherishly identity verification
  @endif
@endsection

@section('content')

  @if($type === 'email_verification')
    {{-- Email Verification --}}
    <p class="email-greeting">Verify your email address 👋</p>
    <p class="email-text">
      Hi <strong>{{ $user->first_name }}</strong>, welcome to Cherishly!
      We're excited to have you on board. Please use the code below
      to verify your email address and get started.
    </p>

  @elseif($type === 'password_reset')
    {{-- Password Reset --}}
    <p class="email-greeting">Reset your password 🔐</p>
    <p class="email-text">
      Hi <strong>{{ $user->first_name }}</strong>, we received a request
      to reset the password for your Cherishly account. Use the code
      below to proceed. If you didn't request this, you can safely ignore this email.
    </p>

  @else
    {{-- Identity Verification --}}
    <p class="email-greeting">Verify your identity 🔒</p>
    <p class="email-text">
      Hi <strong>{{ $user->first_name }}</strong>, use the code below
      to verify your identity and complete your request on Cherishly.
    </p>
  @endif

  {{-- OTP Box --}}
  <div class="otp-box">
    <p class="otp-label">Your verification code</p>
    <p class="otp-code">{{ $otp }}</p>
    <p class="otp-expiry">
      This code expires in <strong>10 minutes</strong>
    </p>
  </div>

  {{-- Warning --}}
  <div class="warning-box">
    <p>
      🔒 <strong>Keep this code private.</strong>
      Cherishly will never ask for your OTP via phone, chat, or email.
      Do not share this code with anyone.
    </p>
  </div>

  <hr class="email-divider" />

  <p class="email-text" style="font-size: 13px; color: #9B7B7B;">
    Didn't request this? If you didn't create a Cherishly account or
    request this code, please ignore this email or
    <a href="https://cherishlyng.com/contact" style="color: #E8604A;">contact our support team</a>
    if you have concerns.
  </p>

@endsection
