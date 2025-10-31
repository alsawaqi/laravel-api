<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; // optional (you can leave it even if queue not used yet)
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

class VerifyEmailCustom extends Notification 
// implements ShouldQueue
{
   // use Queueable;

    public function __construct()
    {
        //
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
       $verificationUrl = $this->verificationUrl($notifiable);

        // Instead of building with ->greeting()->line()->action(), we directly
        // attach our blade view and pass the data.
        return (new MailMessage)
            ->subject('Verify your email to activate your ISC account')
            ->view(
                'emails.verify-account', // <-- the Blade we created
                [
                    'username'         => $notifiable->User_Name ?? '',
                    'verificationUrl'  => $verificationUrl,
                ]
            );
    }

    protected function verificationUrl($notifiable)
    {
        // We are generating a temporary signed URL to the route we defined:
        // Route name: verification.verify
        // Route path: /api/email/verify/{id}/{hash}

        // The hash must match what we check in the route: sha1(email)
        return URL::temporarySignedRoute(
            'verification.verify', // <-- must match ->name('verification.verify')
            Carbon::now()->addMinutes(60), // link expires in 60 minutes
            [
                'id' => $notifiable->id,
                'hash' => sha1($notifiable->email),
            ]
        );
    }
}
