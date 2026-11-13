<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AUTH-02 : lien de réinitialisation du mot de passe, valable 30 minutes. */
class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/definir-mot-de-passe?'.http_build_query([
            'type' => 'reset',
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe')
            ->greeting('Bonjour '.explode(' ', $notifiable->name)[0].',')
            ->line('Une réinitialisation de mot de passe a été demandée pour votre compte.')
            ->action('Choisir un nouveau mot de passe', $url)
            ->line('Ce lien est valable 30 minutes. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.')
            ->salutation('— '.config('app.name'));
    }
}
