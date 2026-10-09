<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderDelivered extends Notification implements ShouldQueue
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
        $days = (int) config('shop.refund_window_days');

        return (new MailMessage)
            ->subject("Your order {$this->order->order_number} was delivered")
            ->greeting("Hi {$notifiable->name},")
            ->line("Order {$this->order->order_number} has been delivered. We hope you enjoy it!")
            ->line("If something is not right, you can ask for a refund from your order page for the next {$days} days.")
            ->action('View your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
