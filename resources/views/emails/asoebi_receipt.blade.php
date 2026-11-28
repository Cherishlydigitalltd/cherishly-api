@extends('emails.layouts.base')

@section('subject')
  Your aso-ebi for {{ $event->title }} is reserved
@endsection

@section('content')

  <p class="email-greeting">Payment received 🎉</p>
  <p class="email-text">
    Hi <strong>{{ $order->buyer_name }}</strong>,<br>
    Your aso-ebi for <strong>{{ $event->title }}</strong> is reserved.
    @if ($order->delivery)
      We'll deliver it to the address you gave.
    @else
      Show the code below when you collect it. It works once.
    @endif
  </p>

  <div style="background: #f9f6f1; border-radius: 8px; padding: 16px; margin: 20px 0; font-size: 14px; color: #3D0C0C;">
    <p style="margin: 0 0 6px;"><strong>Order {{ $order->order_no }}</strong></p>
    <p style="margin: 0 0 6px;">{{ $order->quantity }} × {{ $order->package->name }}</p>
    @if ($order->delivery)
      <p style="margin: 0 0 6px;">Delivery to: {{ $order->delivery_address }}</p>
    @endif
    <p style="margin: 0;">Paid: ₦{{ number_format((float) $order->gross_amount, 2) }}</p>
  </div>

  @if (!$order->delivery)
    <div style="text-align: center; margin: 24px 0;">
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=8&data={{ urlencode($orderUrl) }}"
           alt="Your pickup code" style="width: 200px; height: 200px; border: 1px solid #eee; border-radius: 8px;" />
      @if ($event->asoebi_pickup_note)
        <p style="font-size: 13px; color: #555; margin: 12px 0 0; white-space: pre-line;">{{ $event->asoebi_pickup_note }}</p>
      @endif
    </div>
  @endif

  <div style="text-align: center; margin: 24px 0;">
    <a href="{{ $orderUrl }}" class="email-btn">View my order</a>
  </div>

@endsection
