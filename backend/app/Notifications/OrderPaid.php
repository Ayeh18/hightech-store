<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "We received your payment." Queued, so a slow mail server never slows down the payment webhook. */
class OrderPaid extends Notification implements ShouldQueue
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
            ->subject("Payment received for {$this->order->order_number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("We received your payment of {$this->order->total->format()} for order {$this->order->order_number}. Thank you!")
            ->line('We will start preparing your order and let you know when it ships.')
            ->action('View your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
