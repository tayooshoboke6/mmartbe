# Brevo Email Setup Guide for M-Mart+

## Issue Identified
The order confirmation emails are not being delivered because:
1. The mail driver is set to "log" instead of "brevo"
2. The Brevo API key is not configured in the .env file

## How to Fix

Update your `.env` file with the following configuration:

```
# Mail Configuration
MAIL_MAILER=brevo
MAIL_FROM_ADDRESS=noreply@mmartplus.com
MAIL_FROM_NAME="M-Mart+ Support"

# Brevo Configuration
BREVO_API_KEY=your_brevo_api_key_here
```

## Steps to Get Brevo API Key

1. Sign up or log in to your Brevo account at https://app.brevo.com/
2. Go to Settings > SMTP & API
3. Copy your API key (or create a new one if needed)
4. Paste the API key in your .env file as shown above

## Testing the Configuration

After updating your .env file, run the following command to test if emails are working:

```
php artisan email:test mmartplus1@gmail.com
```

## Troubleshooting

If emails still aren't being delivered:

1. Check if the Brevo API key is correct
2. Verify that your Brevo account is active and not in a trial period
3. Check if there are any sending limits on your Brevo account
4. Look for any error messages in the Laravel logs
