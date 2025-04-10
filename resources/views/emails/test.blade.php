<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>M-Mart+ Test Email</title>
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
      word-break: break-word;
      white-space: normal;
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

    .footer {
      background: #173CB2;
      text-align: center;
      padding: 25px;
      font-size: 14px;
      color: rgba(255,255,255,0.8);
    }

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
        padding: 30px 25px;
      }

      .content {
        padding: 30px 25px;
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
      <h1 class="header-title">Test Email</h1>
      <p class="header-subtitle">This is a test email from M-Mart+</p>
    </div>

    <div class="content">
      <h2>Hello {{ $user->name ?? 'Customer' }},</h2>

      <p>This is a test email from M-Mart+ to verify that our email system is working correctly.</p>

      <div class="status-box">
        <p><strong>Test Email ID:</strong> TEST-{{ rand(10000, 99999) }}</p>
        <p><strong>Sent Date:</strong> {{ now()->format('d/m/Y H:i') }}</p>
        <p><strong>Status:</strong> Delivered</p>
      </div>

      <h3 class="section-title"><span>📧</span> Email Information</h3>
      <div class="address-box">
        <p><strong>Recipient:</strong> {{ $email }}</p>
        <p><strong>Sender:</strong> {{ config('mail.from.address') }}</p>
        <p><strong>Subject:</strong> M-Mart+ Test Email</p>
      </div>

      <p>If you've received this email, it means our email system is working properly. You can safely ignore this message.</p>

      <p style="color: #173CB2; font-weight: bold; font-size: 18px; margin-top: 30px; margin-bottom: 30px;">Thank you for using M-Mart+!</p>

      <div style="text-align: center; margin: 40px 0;">
        <a href="#" class="btn">Visit Our Website</a>
      </div>
    </div>

    <div class="footer">
      &copy; {{ date('Y') }} M-Mart+. All rights reserved.
    </div>
  </div>

</body>
</html>
