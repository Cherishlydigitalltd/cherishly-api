@extends('emails.layouts.base')

@section('subject')
  Your aso-ebi for {{ $event->title }} isn't paid yet
@endsection

@section('content')

  <p class="email-greeting">Still want your aso-ebi?</p>
  <p class="email-text">
    Hi <strong>{{ $order->buyer_name }}</strong>,<br>
    You started an order for <strong>{{ $order->quantity }} × {{ $order->package->name }}</strong>
    for <strong>{{ $event->title }}</strong>, but the payment didn't go through.
    @if ($event->asoebi_deadline)
      Orders close on <strong>{{ $event->asoebi_deadline->format('D j M') }}</strong>.
    @endif
  </p>

  <div style="text-align: center; margin: 28px 0;">
    <a href="{{ $shopUrl }}" class="email-btn">Order my aso-ebi</a>
  </div>

  <p class="email-text" style="font-size: 13px; color: #9B7B7B;">If you've already paid, you can ignore this email.</p>

@endsection
