<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>M-Mart+ Welcome</title>
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

    .feature-box {
      background: #f5f5f5;
      padding: 35px 40px;
      border-radius: 12px;
      margin: 40px 0;
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
      border-top: 4px solid #173CB2;
    }

    .feature-box h3 {
      color: #173CB2;
      margin-top: 0;
    }

    .feature-box p {
      margin: 12px 0;
      line-height: 1.6;
    }

    .feature-grid {
      width: 100%;
      margin: 30px 0;
      overflow: hidden;
    }

    .feature-item {
      width: 45%;
      float: left;
      margin-right: 5%;
      margin-bottom: 20px;
      background: #fff;
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.05);
      border-left: 3px solid #F6C004;
      box-sizing: border-box;
    }
    
    .feature-item:nth-child(2n) {
      margin-right: 0;
    }

    .feature-item h4 {
      color: #173CB2;
      margin-top: 0;
      font-size: 16px;
    }

    .feature-item p {
      margin: 10px 0 0;
      font-size: 14px;
      line-height: 1.5;
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

      .status-box, .feature-box {
        padding: 25px;
        margin: 25px 0;
      }

      .feature-item {
        width: 100%;
        float: none;
        margin-right: 0;
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
      <h1 class="header-title">Welcome to M-Mart+</h1>
      <p class="header-subtitle">Your account has been created successfully!</p>
    </div>

    <div class="content">
      <h2>Hello {{ $user->name }},</h2>

      <p>Thank you for joining M-Mart+! We're excited to have you as part of our community. Your account has been successfully created and you can now enjoy all the benefits of shopping with us.</p>

      <div class="status-box">
        <p><strong>Account Email:</strong> {{ $user->email }}</p>
        <p><strong>Account Created:</strong> {{ $user->created_at->format('d/m/Y H:i') }}</p>
        <p><strong>Account Status:</strong> Active</p>
      </div>

      <h3 class="section-title"><span>✨</span> What You Can Do Now</h3>
      
      <div class="feature-grid">
        <div class="feature-item">
          <h4>Browse Products</h4>
          <p>Explore our wide range of products at competitive prices.</p>
        </div>
        <div class="feature-item">
          <h4>Save Favorites</h4>
          <p>Add items to your wishlist to save them for later.</p>
        </div>
        <div class="feature-item">
          <h4>Track Orders</h4>
          <p>Follow your orders from purchase to delivery.</p>
        </div>
        <div class="feature-item">
          <h4>Get Exclusive Deals</h4>
          <p>Access special promotions and discounts for members.</p>
        </div>
      </div>

      <h3 class="section-title"><span>🔒</span> Account Security</h3>
      <p>We take your security seriously. Here are some tips to keep your account safe:</p>
      <ul>
        <li>Use a strong, unique password</li>
        <li>Never share your login credentials</li>
        <li>Log out when using shared devices</li>
        <li>Update your contact information regularly</li>
      </ul>

      <p>If you have any questions or need assistance, our customer support team is always ready to help you.</p>

      <p style="color: #173CB2; font-weight: bold; font-size: 18px; margin-top: 30px; margin-bottom: 30px;">Thank you for choosing M-Mart+!</p>

      <div style="text-align: center; margin: 40px 0;">
        <a href="{{ url('/') }}" class="btn">Start Shopping</a>
      </div>
    </div>

    <div class="footer">
      &copy; {{ date('Y') }} M-Mart+. All rights reserved.
    </div>
  </div>

</body>
</html>
