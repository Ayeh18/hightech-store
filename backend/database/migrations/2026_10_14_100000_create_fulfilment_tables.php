<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The parcel. One per order for now (the unique key says so); it is what the customer sees as "Shipped".
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('carrier', 60);
            $table->string('tracking_number', 100);
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        // Every change of an order's status, written by an observer so no code path can forget it. It gives the customer
        // their timeline, and lets "reject a refund" put the order back to exactly where it was.
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = the system (a webhook, the expiry job)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('shipments');
    }
};
