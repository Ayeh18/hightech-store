<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the people who look after stock: a product has just dropped to (or below) its own alert level. */
class LowStock extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Product $product, public readonly int $available, public readonly int $threshold)
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
        $left = $this->available === 0 ? 'It is now sold out.' : "Only {$this->available} can still be sold.";

        return (new MailMessage)
            ->subject(($this->available === 0 ? 'Sold out: ' : 'Low stock: ').$this->product->name)
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->product->name} ({$this->product->sku}) is running low. {$left} Its alert level is {$this->threshold}.")
            ->action('Restock it', config('shop.frontend_url')."/admin/products/{$this->product->id}");
    }
}
