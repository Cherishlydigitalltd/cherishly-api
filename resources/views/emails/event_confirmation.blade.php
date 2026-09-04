@extends('emails.layouts.base')

@section('subject')
  ✅ You're Confirmed! Your Event Pass for {{ $event->title }}
@endsection

@section('content')

  <p class="email-greeting">You're Confirmed! 🎉</p>
  <p class="email-text">
    Hi <strong>{{ $guest->full_name }}</strong>,<br>
    Your attendance for <strong>{{ $event->title }}</strong> has been confirmed.
    Please save this email — it contains your personal QR code for event check-in.
  </p>

  @if($event->event_date || $event->venue)
    <div class="warning-box">
      @if($event->event_date)
        <p>📅 Date: <strong>{{ \Carbon\Carbon::parse($event->event_date)->format('l, j F Y') }}</strong></p>
      @endif
      @if($event->venue)
        <p>📍 Venue: <strong>{{ $event->venue }}</strong></p>
      @endif
    </div>
  @endif

  {{-- QR Code section --}}
  <div style="text-align: center; margin: 32px 0;">
    <p style="font-size: 14px; color: #555; margin-bottom: 16px; font-weight: 600;">
      Your Unique Event Pass QR Code
    </p>
    <img
      src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode($checkinUrl) }}"
      alt="Your Event Pass QR Code"
      style="width: 200px; height: 200px; border: 1px solid #eee; border-radius: 8px; padding: 8px;"
    />
    <p style="font-size: 12px; color: #999; margin-top: 12px;">
      Show this QR code at the event entrance for check-in
    </p>
  </div>

  <div style="background: #f9f9f9; border-radius: 8px; padding: 16px; margin-bottom: 24px; text-align: center;">
    <p style="font-size: 13px; color: #555; margin: 0 0 4px;">Guest: <strong>{{ $guest->full_name }}</strong></p>
    <p style="font-size: 13px; color: #555; margin: 0 0 4px;">Event: <strong>{{ $event->title }}</strong></p>
    <span style="display: inline-block; background: #dcfce7; color: #16a34a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-top: 8px;">
      ✅ RSVP Confirmed
    </span>
  </div>

  <hr class="email-divider" />

  <p class="email-text" style="font-size: 13px; color: #9B7B7B; text-align: center;">
    Please keep this email safe — your QR code is unique and required for entry.<br>
    We look forward to seeing you!
  </p>

@endsection
