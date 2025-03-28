@component('mail::message')
# New Newsletter Subscription

A new user has subscribed to your newsletter.

## Subscriber Details

**Name:** {{ $subscriber->name }}  
**Email:** {{ $subscriber->email }}  
**Subscribed On:** {{ $subscriber->updated_at->format('d/m/Y H:i') }}

@component('mail::button', ['url' => config('app.frontend_url') . '/admin/users/' . $subscriber->id])
View User Profile
@endcomponent

This user will now receive your marketing and promotional emails.

Regards,<br>
{{ config('app.name') }} System
@endcomponent
