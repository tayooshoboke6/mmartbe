@component('mail::message')
# {{ $subject }}

Dear {{ $user->name }},

{!! $content !!}

@if(isset($unsubscribeUrl))
@component('mail::button', ['url' => $unsubscribeUrl])
Unsubscribe
@endcomponent
@endif

Thank you for being a valued customer of M-Mart+!

Regards,<br>
{{ config('app.name') }}

<small>If you no longer wish to receive these emails, you can unsubscribe by visiting your account settings.</small>
@endcomponent
