<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your payment did not go through, and your items are still reserved until ..." */
class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order, public readonly ?string $reason = null)
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
            ->subject("Payment problem for {$this->order->order_number}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your payment for order {$this->order->order_number} did not go through.".($this->reason ? " Reason: {$this->reason}." : ''));

        if ($this->order->expires_at?->isFuture()) {
            $message->line("Your items are still reserved until {$this->order->expires_at->timezone(config('app.timezone'))->format('g:i A')}. You can try paying again before then.");
        }

        return $message->action('Try again', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
