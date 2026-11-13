<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base des notifications de l'application (NOT-01, NOT-02, NOT-05).
 *
 * - Toujours visible dans l'application (cloche), enregistrée immédiatement.
 * - Envoyée aussi par e-mail si l'utilisateur ne l'a pas désactivé pour cette catégorie ;
 *   l'e-mail part par la file d'attente pour ne pas ralentir la requête.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Catégories proposées dans les préférences, avec leur libellé. */
    public const CATEGORIES = [
        'leave_to_approve' => 'Demandes de congé à valider',
        'leave_decision' => 'Réponses à mes demandes de congé',
        'contract_deadline' => 'Échéances de contrats et de périodes d’essai',
        'documents' => 'Documents (attestations, pièces qui expirent, documents à lire)',
        'attendance' => 'Présences (heures supplémentaires, corrections, retards et absences)',
        'evaluations' => 'Entretiens d’évaluation (auto-évaluation, compte rendu à signer, rappels)',
    ];

    abstract public function category(): string;

    abstract public function title(): string;

    abstract public function message(): string;

    /** Route de l'application React vers laquelle mène la notification. */
    abstract public function url(): string;

    public function via(User $notifiable): array
    {
        return $notifiable->wantsEmailFor($this->category()) ? ['database', 'mail'] : ['database'];
    }

    /** La cloche est mise à jour sans attendre le traitement de la file d'attente. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(User $notifiable): array
    {
        return [
            'category' => $this->category(),
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Bonjour '.explode(' ', $notifiable->name)[0].',')
            ->line($this->message())
            ->action('Ouvrir dans l’application', rtrim(config('app.frontend_url'), '/').$this->url())
            ->salutation('— '.config('app.name'));
    }
}
