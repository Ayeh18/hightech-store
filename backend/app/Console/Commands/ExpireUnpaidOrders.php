<?php

namespace App\Console\Commands;

use App\Services\Orders\OrderService;
use Illuminate\Console\Command;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Cancel unpaid orders whose payment window has passed and give their stock back';

    public function handle(OrderService $orders): int
    {
        $count = $orders->expireOverdue();

        $this->info("Expired {$count} unpaid ".($count === 1 ? 'order' : 'orders').'.');

        return self::SUCCESS;
    }
}
