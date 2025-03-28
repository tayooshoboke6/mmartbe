@component('mail::message')
# Order Confirmation

Dear {{ $user->name }},

Thank you for your order with M-Mart+. Your order has been received and is being processed.

**Order Number:** {{ $order->order_number }}  
**Order Date:** {{ $order->created_at->format('d/m/Y H:i') }}  
**Payment Method:** {{ ucfirst(str_replace('_', ' ', $order->payment_method)) }}  
**Order Status:** {{ ucfirst($order->status) }}

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

@if($order->delivery_method == 'shipping')
## Shipping Information

**Address:** {{ $order->shipping_address }}  
**City:** {{ $order->shipping_city }}  
**State:** {{ $order->shipping_state }}  
**Zip Code:** {{ $order->shipping_zip_code }}  
**Phone:** {{ $order->shipping_phone }}
@else
## Pickup Information

Your order will be available for pickup at our designated location.
Please bring your order number and a valid ID when collecting your order.
@endif

@component('mail::button', ['url' => $orderUrl])
View Order Details
@endcomponent

If you have any questions about your order, please contact our customer service team.

Thank you for shopping with M-Mart+!

Regards,  
The M-Mart+ Team
@endcomponent
