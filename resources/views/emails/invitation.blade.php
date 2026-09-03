@extends('emails.layouts.base')

@section('subject')
    You're Invited: {{ $invitation->title }}
@endsection

@section('content')

    <p class="email-greeting">You're Invited! 🎉</p>
    <p class="email-text">
        Hi <strong>{{ $guest->full_name }}</strong>,<br>
        You have been invited to <strong>{{ $invitation->title }}</strong>.
        @if($invitation->description)
            <br><br>{{ $invitation->description }}
        @endif
    </p>

    @if($invitation->rsvp_deadline)
        <div class="warning-box">
            <p>📅 Please RSVP by <strong>{{ \Carbon\Carbon::parse($invitation->rsvp_deadline)->format('j F Y') }}</strong></p>
        </div>
    @endif

    @if($invitation->cover_photo)
        <div style="text-align: center; margin: 24px 0;">
            <img src="{{ $invitation->cover_photo }}" alt="{{ $invitation->title }}"
                style="max-width: 100%; border-radius: 12px;" />
        </div>
    @endif

    {{-- RSVP buttons --}}
    <div style="text-align: center; margin: 32px 0;">
        <p class="email-text" style="margin-bottom: 16px;">Will you be joining us?</p>
        <a href="{{ $rsvpUrl }}&status=attending"
            style="display: inline-block; padding: 14px 32px; background: #8B0000; color: #fff; border-radius: 8px; font-weight: 700; font-size: 15px; text-decoration: none; margin-right: 10px;">
            ✓ Yes, I'll be there
        </a>
        <a href="{{ $rsvpUrl }}&status=declined"
            style="display: inline-block; padding: 14px 32px; background: #f3f4f6; color: #555; border-radius: 8px; font-weight: 700; font-size: 15px; text-decoration: none;">
            ✗ Can't make it
        </a>
    </div>

    <hr class="email-divider" />

    <p class="email-text" style="font-size: 13px; color: #9B7B7B; text-align: center;">
        Or visit your personal RSVP page:
        <a href="{{ $rsvpUrl }}" style="color: #E8604A;">Click here to RSVP</a>
    </p>

    <p class="email-text" style="font-size: 12px; color: #bbb; text-align: center; margin-top: 8px;">
        If you believe this was sent in error, please ignore this email.
    </p>

@endsection