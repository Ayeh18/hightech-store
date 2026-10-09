<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order, public readonly Refund $refund)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("About your refund request for {$this->order->order_number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("We could not approve the refund you asked for on order {$this->order->order_number}.");

        if ($this->refund->review_note) {
            $message->line("Reason: {$this->refund->review_note}");
        }

        return $message->action('View your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
