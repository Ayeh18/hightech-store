<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the customer ACTUALLY paid on the pretend payment company's page, when that is not what was asked for. It lets
        // the fake payment page show what reconciliation catches (the real world has short payments, currency slips...).
        Schema::table('fake_gateway_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_amount')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fake_gateway_payments', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
