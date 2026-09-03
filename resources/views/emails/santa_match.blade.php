@extends('emails.layouts.base')

@section('subject')
  🎅 Your Secret Santa match is ready!
@endsection

@section('content')

  <p class="email-greeting">Your Secret Santa match is ready! 🎅</p>
  <p class="email-text">
    Hi <strong>{{ $giver->name }}</strong>,<br>
    The matches for <strong>{{ $santa->title }}</strong> have been generated.
    You have been assigned a secret recipient!
  </p>

  <div class="otp-box">
    <p class="otp-label">Your Secret Code</p>
    <p class="otp-code">{{ $code }}</p>
    <p class="otp-expiry">Use this code to reveal your match on the Secret Santa page</p>
  </div>

  <div style="text-align: center; margin: 24px 0;">
    <a href="{{ $revealUrl }}"
      style="display: inline-block; padding: 14px 32px; background: #8B0000; color: #fff; border-radius: 8px; font-weight: 700; font-size: 15px; text-decoration: none;">
      🎁 Reveal My Match
    </a>
  </div>

  @if($santa->budget)
    <div class="warning-box">
      <p>💰 Gift Budget: <strong>₦{{ number_format($santa->budget, 2) }}</strong></p>
    </div>
  @endif

  <hr class="email-divider" />

  <p class="email-text" style="font-size: 13px; color: #9B7B7B; text-align: center;">
    Keep your match a secret until the gift exchange! 🤫
  </p>

@endsection
