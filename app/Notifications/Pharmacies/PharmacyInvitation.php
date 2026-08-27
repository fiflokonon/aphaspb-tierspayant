<?php

namespace App\Notifications\Pharmacies;

use App\Models\PharmacyInvitation as PharmacyInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PharmacyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public PharmacyInvitationModel $invitation)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $pharmacy = $this->invitation->pharmacy;
        $inviter = $this->invitation->inviter;

        return (new MailMessage)
            ->subject(__("You've been invited to join :pharmacyName", ['pharmacyName' => $pharmacy->name]))
            ->line(__(':inviterName has invited you to join the :pharmacyName pharmacy.', [
                'inviterName' => $inviter->name,
                'pharmacyName' => $pharmacy->name,
            ]))
            ->line(__('Log in and visit your dashboard to accept or decline this invitation.'))
            ->action(
                __('Log in'),
                route('login', ['invitation' => $this->invitation->code]),
            );
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'pharmacy_id' => $this->invitation->pharmacy_id,
            'pharmacy_name' => $this->invitation->pharmacy->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
