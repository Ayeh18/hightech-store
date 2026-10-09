<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Unpaid orders hold stock. Give it back once their payment window has passed.
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping();
