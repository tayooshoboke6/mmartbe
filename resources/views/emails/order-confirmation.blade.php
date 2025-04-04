<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>M-Mart+ Order Confirmation</title>
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f0f4f8;
      color: #333;
      margin: 0;
      padding: 0;
      -webkit-font-smoothing: antialiased;
      max-width: 100vw;
      overflow-x: hidden;
    }

    .email-container {
      max-width: 650px;
      margin: 40px auto;
      background: #fff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }

    .header {
      background: linear-gradient(135deg, #173CB2, #0075DA, #00A3E0);
      background-size: 300% 300%;
      color: #fff;
      padding: 40px 60px;
      text-align: center;
    }
    
    .header > * {
      display: block;
      margin-left: auto;
      margin-right: auto;
      clear: both;
      float: none;
      width: 100%;
    }

    .logo-container {
      margin-bottom: 15px;
    }

    .logo-container img {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      border: 3px solid rgba(255,255,255,0.3);
      padding: 5px;
      background: rgba(255,255,255,0.1);
    }

    .header-title {
      font-size: 28px;
      font-weight: bold;
      color: #F6C004;
      text-shadow: 0 2px 4px rgba(0,0,0,0.2);
      margin: 0;
      padding: 0;
    }

    .header-subtitle {
      color: white;
      font-size: 16px;
      margin-top: 8px;
      opacity: 0.9;
    }

    .content {
      padding: 45px 60px;
    }
    
    .content p {
      margin: 16px 0;
      line-height: 1.6;
      padding-left: 5px;
      padding-right: 5px;
    }

    .content h2 {
      margin-top: 0;
      color: #173CB2;
      font-size: 24px;
      border-bottom: 2px solid #f0f0f0;
      padding-bottom: 20px;
      margin-bottom: 35px;
    }

    .status-box {
      background: #f0f7ff;
      padding: 35px 40px;
      border-left: 5px solid #0075DA;
      margin: 40px 0;
      border-radius: 12px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    .status-box p {
      margin: 12px 0;
      font-weight: 500;
      font-size: 15px;
      line-height: 1.5;
    }

    .order-summary {
      margin-top: 25px;
      border-collapse: separate;
      border-spacing: 0;
      width: 100%;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    .order-summary th, .order-summary td {
      padding: 15px;
      border-bottom: 1px solid #e0e0e0;
      text-align: left;
    }

    .order-summary th {
      background-color: #173CB2;
      color: white;
      font-weight: 600;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .order-summary tr:last-child td {
      border-bottom: none;
    }

    .order-summary tr:nth-child(even) {
      background-color: #f9f9f9;
    }

    .total-row td {
      font-weight: bold;
      background-color: #f0f7ff;
      font-size: 16px;
    }

    /* Footer styles removed and replaced with inline styles */

    .btn {
      display: inline-block;
      background: linear-gradient(to right, #F6C004, #F9A826);
      color: #173CB2;
      font-weight: bold;
      padding: 14px 28px;
      text-decoration: none;
      border-radius: 30px;
      margin-top: 30px;
      box-shadow: 0 4px 15px rgba(246,192,4,0.3);
      transition: transform 0.2s;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(246,192,4,0.4);
    }

    .address-box {
      background: #f5f5f5;
      padding: 35px 40px;
      border-radius: 12px;
      margin: 40px 0;
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
      border-top: 4px solid #173CB2;
    }

    .address-box p {
      margin: 12px 0;
      line-height: 1.6;
    }

    .section-title {
      display: flex;
      align-items: center;
      margin: 40px 0 20px 0;
      color: #173CB2;
      font-size: 20px;
    }

    .section-title span {
      margin-right: 10px;
      font-size: 24px;
    }

    @media only screen and (max-width: 600px) {
      .email-container {
        margin: 0;
        border-radius: 0;
        width: 100%;
      }

      .header {
        padding: 30px 35px;
      }

      .content {
        padding: 30px 35px;
      }

      .status-box, .address-box {
        padding: 25px;
        margin: 25px 0;
      }

      .order-summary th, .order-summary td {
        padding: 10px;
        font-size: 14px;
      }

      .header-title {
        font-size: 24px;
      }
    }
  </style>
</head>
<body>

  <div class="email-container">
    <div class="header">
      <div class="logo-container">
        <img src="{{ asset('images/logo-icon.png') }}" alt="M-Mart+ Logo">
      </div>
      <h1 class="header-title" style="padding: 0 10px;">Order Confirmation</h1>
      <p class="header-subtitle" style="padding: 0 10px;">Thank you for your purchase!</p>
    </div>

    <div class="content" style="margin-top: 25px;">
      <h2 style="padding: 0 5px;">Hello {{ $user->name ?? 'Customer' }},</h2>

      <p style="padding: 0 5px;">We're excited to confirm that we've received your order and it's now being processed. Below you'll find all the details of your purchase.</p>

      <div class="status-box">
        <p><strong>Order Number:</strong> <span style="white-space: nowrap;">{{ $order->order_number }}</span></p>
        <p><strong>Order Date:</strong> <span style="white-space: nowrap;">{{ $order->created_at->format('d/m/Y H:i') }}</span></p>
        <p><strong>Payment Method:</strong> <span style="white-space: nowrap;">{{ ucfirst(str_replace('_', ' ', $order->payment_method)) }}</span></p>
        <p><strong>Order Status:</strong> <span style="white-space: nowrap;">{{ ucfirst($order->status) }}</span></p>
        <p><strong>Payment Status:</strong> <span style="white-space: nowrap;">{{ ucfirst($order->payment_status) }}</span></p>
      </div>

      <h3 class="section-title"><span>🛍️</span> Items Ordered</h3>
      <table class="order-summary">
        <thead>
          <tr>
            <th style="width: 45%;">Item</th>
            <th style="width: 10%;">Qty</th>
            <th style="width: 20%;">Price</th>
            <th style="width: 25%;">Total</th>
          </tr>
        </thead>
        <tbody>
          @foreach($orderItems as $item)
            <tr>
              <td style="max-width: 200px; overflow-wrap: break-word; word-wrap: break-word;">{{ $item->product_name }}</td>
              <td>{{ $item->quantity }}</td>
              <td style="white-space: nowrap;"><nobr>₦{{ number_format($item->unit_price, 2) }}</nobr></td>
              <td style="white-space: nowrap;"><nobr>₦{{ number_format($item->subtotal, 2) }}</nobr></td>
            </tr>
          @endforeach

          <tr>
            <td colspan="3">Subtotal</td>
            <td style="white-space: nowrap;"><nobr>₦{{ number_format($order->subtotal, 2) }}</nobr></td>
          </tr>

          @if($order->discount > 0)
          <tr>
            <td colspan="3">Discount</td>
            <td style="white-space: nowrap;"><nobr>-₦{{ number_format($order->discount, 2) }}</nobr></td>
          </tr>
          @endif

          <tr>
            <td colspan="3">Shipping</td>
            <td style="white-space: nowrap;"><nobr>₦{{ number_format($order->shipping_fee, 2) }}</nobr></td>
          </tr>

          <tr>
            <td colspan="3">Tax</td>
            <td style="white-space: nowrap;"><nobr>₦{{ number_format($order->tax, 2) }}</nobr></td>
          </tr>

          <tr class="total-row">
            <td colspan="3">Total</td>
            <td style="white-space: nowrap;"><nobr>₦{{ number_format($order->grand_total, 2) }}</nobr></td>
          </tr>
        </tbody>
      </table>

      @if($order->delivery_method == 'shipping')
        <h3 class="section-title"><span>📦</span> Shipping Information</h3>
        <div class="address-box">
          <p><strong>Address:</strong> <span style="white-space: nowrap;">{{ $order->shipping_address }}</span></p>
          <p><strong>City:</strong> <span style="white-space: nowrap;">{{ $order->shipping_city }}</span></p>
          <p><strong>State:</strong> <span style="white-space: nowrap;">{{ $order->shipping_state }}</span></p>
          <p><strong>Zip Code:</strong> <span style="white-space: nowrap;">{{ $order->shipping_zip_code }}</span></p>
          <p><strong>Phone:</strong> <span style="white-space: nowrap;">{{ $order->shipping_phone }}</span></p>
        </div>
      @else
        <h3 class="section-title"><span>🏬</span> Pickup Information</h3>
        <div class="address-box">
          <p>Your order will be available for pickup at our designated location.</p>
          <p>Please bring your order number and a valid ID when collecting your order.</p>
        </div>
      @endif

      <p>We'll notify you once your order is out for delivery or ready for pickup. If you have any questions about your order, please don't hesitate to contact our customer service team.</p>

      <p style="color: #173CB2; font-weight: bold; font-size: 18px; margin-top: 30px; margin-bottom: 30px;">Thank you for shopping with M-Mart+!</p>

      <div style="text-align: center; margin: 40px 0;">
        <a href="{{ $orderUrl }}" class="btn">View Order Details</a>
      </div>
    </div>

    <table width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#173CB2" style="background-color: #173CB2;">
      <tr>
        <td align="center" style="padding: 25px 0;">
          <table width="250" border="0" cellpadding="0" cellspacing="0">
            <tr>
              <td align="center" style="text-align:center; font-size: 14px; color: rgba(255,255,255,0.8);">
                &copy; {{ date('Y') }} M-Mart+. All rights reserved.
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

</body>
</html>
