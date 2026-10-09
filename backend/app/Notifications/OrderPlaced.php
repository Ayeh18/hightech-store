<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "We have your order", with what to do next: pay before the items are released. */
class OrderPlaced extends Notification implements ShouldQueue
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
        $message = (new MailMessage)
            ->subject("We received your order {$this->order->order_number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Thank you! Order {$this->order->order_number} for {$this->order->total->format()} is waiting for payment.");

        if ($this->order->expires_at) {
            $until = $this->order->expires_at->timezone(config('shop.timezone'))->format('g:i A');
            $message->line("We are holding your items until {$until}. After that the order is cancelled automatically and they go back on sale.");
        }

        return $message->action('Pay for your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
