@extends('emails.layouts.base')

@section('subject')
  You're Invited: {{ $event->title }}
@endsection

@section('content')

  <p class="email-greeting">You're Invited! 🎉</p>
  <p class="email-text">
    Hi <strong>{{ $guest->full_name }}</strong>,<br>
    You have been invited to <strong>{{ $event->title }}</strong>.
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

  @if($event->description)
    <p class="email-text">{{ $event->description }}</p>
  @endif

  @if($event->cover_photo)
    <div style="text-align: center; margin: 24px 0;">
      <img src="{{ $event->cover_photo }}" alt="{{ $event->title }}"
        style="max-width: 100%; border-radius: 12px;" />
    </div>
  @endif

  <div style="text-align: center; margin: 32px 0;">
    <a href="{{ $rsvpUrl }}"
      style="display: inline-block; padding: 14px 32px; background: #8B0000; color: #fff; border-radius: 8px; font-weight: 700; font-size: 15px; text-decoration: none;">
      RSVP Now
    </a>
  </div>

  <hr class="email-divider" />

  <p class="email-text" style="font-size: 13px; color: #9B7B7B; text-align: center;">
    If you believe this was sent in error, please ignore this email.
  </p>

@endsection
