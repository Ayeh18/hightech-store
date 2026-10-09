<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your order is on its way", with the carrier and tracking number. */
class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order, public readonly Shipment $shipment)
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
            ->subject("Your order {$this->order->order_number} has shipped")
            ->greeting("Hi {$notifiable->name},")
            ->line("Good news: order {$this->order->order_number} is on its way.")
            ->line("Carrier: {$this->shipment->carrier}")
            ->line("Tracking number: {$this->shipment->tracking_number}")
            ->action('View your order', config('shop.frontend_url')."/orders/{$this->order->id}");
    }
}
