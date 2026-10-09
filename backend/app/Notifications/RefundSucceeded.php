<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundSucceeded extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject("Refund of {$this->refund->amount->format()} for {$this->order->order_number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("We have refunded {$this->refund->amount->format()} for order {$this->order->order_number} to your original payment method.")
            ->line('It can take a few days to appear, depending on your bank.')
            ->action('View your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
