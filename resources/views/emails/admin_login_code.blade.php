@extends('emails.layouts.base')

@section('subject')
  Your Cherishly admin sign-in code
@endsection

@section('content')
  <p class="email-greeting">Admin sign-in 🔐</p>
  <p class="email-text">
    Hi <strong>{{ $admin->first_name }}</strong>, someone (hopefully you) entered your password
    on the Cherishly admin dashboard. Enter this code to finish signing in.
  </p>

  <div class="otp-box">
    <p class="otp-label">Your sign-in code</p>
    <p class="otp-code">{{ $code }}</p>
    <p class="otp-expiry">This code expires in <strong>10 minutes</strong></p>
  </div>

  <div class="warning-box">
    <p>
      🔒 <strong>Didn't try to sign in?</strong> Someone knows your admin password.
      Change it straight away under My account, and tell the other admins.
      The attempt came from IP address {{ $ip }}.
    </p>
  </div>
@endsection
