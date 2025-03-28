<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed - M-Mart+</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .error-icon {
            color: #dc3545;
            font-size: 64px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .error-message {
            background-color: #f8d7da;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 30px;
            color: #721c24;
        }
        .btn {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s;
            margin: 0 10px;
        }
        .btn:hover {
            background-color: #0069d9;
        }
        .btn-outline {
            background-color: transparent;
            border: 2px solid #007bff;
            color: #007bff;
        }
        .btn-outline:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="error-icon">✗</div>
        <h1>Payment Failed</h1>
        <p>We're sorry, but there was an issue processing your payment.</p>
        
        <div class="error-message">
            @if (session('error'))
                {{ session('error') }}
            @else
                Your payment could not be completed. Please try again or contact customer support if the problem persists.
            @endif
        </div>
        
        <div>
            <a href="/" class="btn">Return to Homepage</a>
            <a href="/contact" class="btn btn-outline">Contact Support</a>
        </div>
    </div>
</body>
</html>
