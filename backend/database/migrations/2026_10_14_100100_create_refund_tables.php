<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How much of this payment has been given back. Only changes when a refund succeeds.
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_amount')->default(0)->after('amount');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_refunded_within_amount CHECK (refunded_amount <= amount)');

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Financial records are never deleted with their order or payment.
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');                 // sen
            $table->string('currency', 3)->default('MYR');
            // REQUESTED (customer asked) -> PROCESSING (approved, being sent to the gateway) -> SUCCEEDED | FAILED;
            // or REQUESTED -> REJECTED. A refund started by staff begins at PROCESSING.
            $table->string('status', 12);
            $table->string('reason', 24);                         // App\Enums\RefundReason
            $table->string('note', 500)->nullable();              // the customer's own words (or why staff started it)
            $table->string('review_note', 500)->nullable();       // what staff said when approving or declining
            $table->boolean('restock')->default(false);           // do the goods go back on the shelf when this succeeds?
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('provider_reference', 100)->nullable(); // the gateway's id for the refund
            $table->string('failure_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('order_id');
            $table->index('payment_id');
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
        // A customer can have only one refund request waiting for review per order.
        DB::statement("CREATE UNIQUE INDEX refunds_one_open_request_per_order ON refunds (order_id) WHERE status = 'REQUESTED'");

        // The pretend payment company's record of refunds. The idempotency key is what stops a retry from refunding twice.
        Schema::create('fake_gateway_refunds', function (Blueprint $table) {
            $table->string('reference', 60)->primary();
            $table->string('payment_reference', 60);
            $table->string('idempotency_key', 64)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('status', 12)->default('succeeded');
            $table->timestamps();

            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_gateway_refunds');
        Schema::dropIfExists('refunds');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refunded_within_amount');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};
