<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>M-Mart+ Password Reset</title>
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
      <h1 class="header-title">Reset Your Password</h1>
      <p class="header-subtitle">We received a request to reset your password</p>
    </div>

    <div class="content">
      <h2>Hello,</h2>

      <p>We received a request to reset the password for your M-Mart+ account. If you didn't make this request, you can safely ignore this email.</p>

      <div class="status-box">
        <p><strong>Account Email:</strong> {{ $email }}</p>
        <p><strong>Request Time:</strong> {{ now()->format('d/m/Y H:i') }}</p>
        <p><strong>Link Expires:</strong> {{ now()->addMinutes(60)->format('d/m/Y H:i') }}</p>
      </div>

      <h3 class="section-title"><span>🔐</span> Reset Your Password</h3>
      
      <p>To reset your password, click the button below. This link will expire in 60 minutes.</p>

      <div style="text-align: center; margin: 40px 0;">
        <a href="{{ $resetUrl }}" class="btn">Reset Password</a>
      </div>

      <p>If the button above doesn't work, copy and paste the following URL into your browser:</p>
      <p style="background-color: #f5f5f5; padding: 15px; border-radius: 8px; word-break: break-all; font-family: monospace; font-size: 14px;">{{ $resetUrl }}</p>

      <h3 class="section-title"><span>🔒</span> Security Tips</h3>
      <ul>
        <li>Create a strong password that includes uppercase and lowercase letters, numbers, and special characters</li>
        <li>Never share your password with anyone</li>
        <li>Use a unique password that you don't use for other websites</li>
        <li>Consider changing your password regularly</li>
      </ul>

      <p>If you didn't request a password reset, please contact our support team immediately.</p>

      <p style="color: #173CB2; font-weight: bold; font-size: 18px; margin-top: 30px; margin-bottom: 30px;">Thank you for shopping with M-Mart+!</p>
    </div>

    <div class="footer" style="background: #173CB2; text-align: center; padding: 25px; font-size: 14px; color: rgba(255,255,255,0.8);">
      &copy; {{ date('Y') }} M-Mart+. All rights reserved.
    </div>
  </div>

</body>
</html>
