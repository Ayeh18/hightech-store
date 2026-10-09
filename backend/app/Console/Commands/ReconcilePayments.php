<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReconciler;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Ask the payment gateway about undecided payments, in case a webhook never arrived';

    public function handle(PaymentReconciler $reconciler): int
    {
        $count = $reconciler->run();

        $this->info("Reconciled {$count} ".($count === 1 ? 'payment' : 'payments').'.');

        return self::SUCCESS;
    }
}
