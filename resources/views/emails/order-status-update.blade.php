@component('mail::message')
# Order Status Update

Dear {{ $user->name }},

Your order status has been updated.

**Order Number:** {{ $order->order_number }}  
**Order Date:** {{ $order->created_at->format('d/m/Y H:i') }}  
**Previous Status:** {{ ucfirst($oldStatus) }}  
**New Status:** {{ ucfirst($newStatus) }}

@if($newStatus == 'processing')
We are currently processing your order and will prepare it for shipping soon.
@elseif($newStatus == 'shipped')
Your order has been shipped and is on its way to you.
@if($order->tracking_number)
**Tracking Number:** {{ $order->tracking_number }}
@endif
@elseif($newStatus == 'delivered')
Your order has been delivered. We hope you are satisfied with your purchase!
@elseif($newStatus == 'cancelled')
Your order has been cancelled. If you have any questions, please contact our customer support.
@endif

## Order Summary

@component('mail::table')
| Product | Quantity | Price | Total |
|:--------|:--------:|:-----:|:-----:|
@foreach($orderItems as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | ₦{{ number_format($item->unit_price, 2) }} | ₦{{ number_format($item->subtotal, 2) }} |
@endforeach
@endcomponent

## Order Total

@component('mail::table')
| | |
|:--------|--------:|
| Subtotal | ₦{{ number_format($order->subtotal, 2) }} |
@if($order->discount > 0)
| Discount | -₦{{ number_format($order->discount, 2) }} |
@endif
| Shipping | ₦{{ number_format($order->shipping_fee, 2) }} |
| Tax | ₦{{ number_format($order->tax, 2) }} |
| **Total** | **₦{{ number_format($order->grand_total, 2) }}** |
@endcomponent

@component('mail::button', ['url' => $orderUrl])
View Order Details
@endcomponent

Thank you for shopping with M-Mart+!

Regards,<br>
{{ config('app.name') }}
@endcomponent
