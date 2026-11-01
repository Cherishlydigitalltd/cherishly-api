@extends('emails.layouts.base')

@section('subject')
  You're confirmed — your pass for {{ $invitation->title }}
@endsection

@section('content')

  <p class="email-greeting">You're confirmed! 🎉</p>
  <p class="email-text">
    Hi <strong>{{ $guest->full_name }}</strong>,<br>
    Thank you for letting us know you'll be at <strong>{{ $invitation->title }}</strong>.
    Below is your personal entry pass — please show it at the door.
  </p>

  <div style="text-align: center; margin: 32px 0;">
    <p style="font-size: 14px; color: #555; margin-bottom: 16px; font-weight: 600;">
      Your entry pass
    </p>
    <img
      src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data={{ urlencode($passUrl) }}"
      alt="Your entry pass QR code"
      style="width: 220px; height: 220px; border: 1px solid #eee; border-radius: 8px;"
    />
    <p style="font-family: monospace; font-size: 12px; color: #555; margin: 10px 0 0; word-break: break-all;">
      {{ $guest->qr_token }}
    </p>
    <p style="font-size: 12px; color: #999; margin-top: 8px;">
      This pass is unique to you — please don't share it.
    </p>
  </div>

  <div style="background: #f9f9f9; border-radius: 8px; padding: 16px; margin-bottom: 24px; text-align: center;">
    <p style="font-size: 13px; color: #555; margin: 0 0 4px;">Guest: <strong>{{ $guest->full_name }}</strong></p>
    <p style="font-size: 13px; color: #555; margin: 0;">Event: <strong>{{ $invitation->title }}</strong></p>
  </div>

  <hr class="email-divider" />

  <p class="email-text" style="font-size: 13px; color: #9B7B7B; text-align: center;">
    We look forward to celebrating with you.
  </p>

@endsection
