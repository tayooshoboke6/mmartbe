<?php

namespace App\Mail;

use Illuminate\Mail\Mailer;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Support\Facades\Log;

class BrevoMailer extends Mailer
{
    /**
     * Send a new message using the given mailable.
     *
     * @param  \Illuminate\Contracts\Mail\Mailable  $mailable
     * @return mixed
     */
    public function send(MailableContract $mailable)
    {
        return $this->sendMailable($mailable);
    }

    /**
     * Send a new message using a view.
     *
     * @param  \Illuminate\Contracts\Mail\Mailable|string|array  $view
     * @param  array  $data
     * @param  \Closure|string|null  $callback
     * @return void
     */
    public function sendMailable($view, array $data = [], $callback = null)
    {
        if ($view instanceof MailableContract) {
            // Ensure all recipients have names for Brevo API
            $this->ensureRecipientsHaveNames($view);
        }

        // Call the parent method to send the email
        return parent::send($view, $data, $callback);
    }

    /**
     * Ensure all recipients in a mailable have names for Brevo API.
     *
     * @param  \Illuminate\Contracts\Mail\Mailable  $mailable
     * @return void
     */
    protected function ensureRecipientsHaveNames($mailable)
    {
        // Get the "to" recipients from the mailable
        $to = $mailable->to;
        
        // If there are "to" recipients, ensure they all have names
        if (!empty($to)) {
            $newTo = [];
            
            foreach ($to as $recipient) {
                // If the recipient is just an email address string
                if (is_string($recipient)) {
                    // Add it with a default name
                    $newTo[] = [
                        'address' => $recipient,
                        'name' => 'User',
                    ];
                } 
                // If the recipient is an array but missing a name
                elseif (is_array($recipient) && isset($recipient['address']) && empty($recipient['name'])) {
                    // Add a default name
                    $recipient['name'] = 'User';
                    $newTo[] = $recipient;
                }
                // Otherwise keep it as is
                else {
                    $newTo[] = $recipient;
                }
            }
            
            // Replace the original recipients with our modified ones
            $mailable->to = $newTo;
        }
        
        // Do the same for CC and BCC recipients
        // CC recipients
        if (!empty($mailable->cc)) {
            $newCc = [];
            
            foreach ($mailable->cc as $recipient) {
                if (is_string($recipient)) {
                    $newCc[] = [
                        'address' => $recipient,
                        'name' => 'User',
                    ];
                } elseif (is_array($recipient) && isset($recipient['address']) && empty($recipient['name'])) {
                    $recipient['name'] = 'User';
                    $newCc[] = $recipient;
                } else {
                    $newCc[] = $recipient;
                }
            }
            
            $mailable->cc = $newCc;
        }
        
        // BCC recipients
        if (!empty($mailable->bcc)) {
            $newBcc = [];
            
            foreach ($mailable->bcc as $recipient) {
                if (is_string($recipient)) {
                    $newBcc[] = [
                        'address' => $recipient,
                        'name' => 'User',
                    ];
                } elseif (is_array($recipient) && isset($recipient['address']) && empty($recipient['name'])) {
                    $recipient['name'] = 'User';
                    $newBcc[] = $recipient;
                } else {
                    $newBcc[] = $recipient;
                }
            }
            
            $mailable->bcc = $newBcc;
        }
    }
}
