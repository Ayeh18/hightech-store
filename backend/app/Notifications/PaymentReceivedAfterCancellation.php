<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The customer paid just after the order had been cancelled (for example because the payment window passed). */
class PaymentReceivedAfterCancellation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order)
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
            ->subject("About your payment for {$this->order->order_number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("We received a payment for order {$this->order->order_number}, but the order had already been cancelled, so we could not keep the items for you.")
            ->line('Your money will be refunded. You are welcome to place the order again.')
            ->action('View your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
