<?php

namespace App\Console\Commands;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:welcome {email?} {user_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test welcome email to a specified email address';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get email from argument or use the first user
            $email = $this->argument('email');
            $userId = $this->argument('user_id');
            
            if ($userId) {
                $user = User::findOrFail($userId);
            } else {
                $user = User::first();
            }
            
            if (!$email) {
                $email = $user->email;
            }
            
            $this->info("Sending welcome email to: {$email}");
            $this->info("Using user: {$user->name} (ID: {$user->id})");
            
            // Log the email attempt
            Log::info('Welcome email command called', [
                'email' => $email,
                'user_id' => $user->id,
                'user_name' => $user->name
            ]);
            
            // Send the email
            Mail::to($email)->send(new WelcomeMail($user));
            
            $this->info('Welcome email sent successfully!');
            return 0;
        } catch (\Exception $e) {
            Log::error('Welcome email command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Failed to send welcome email: ' . $e->getMessage());
            return 1;
        }
    }
}
