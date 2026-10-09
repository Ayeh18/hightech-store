<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the order was paid. Set once, by the payment webhook.
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('cancelled_at');
        });

        // One row per attempt to pay for an order. A failed attempt stays as history; a retry is a new row.
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Financial records outlive everything else: an order with payments cannot be deleted.
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);                  // which gateway: "fake", later a real one
            $table->string('provider_reference', 100)->nullable(); // the gateway's own id for this payment
            $table->string('status', 16);                    // PENDING, PROCESSING, PAID, FAILED, CANCELLED (App\Enums\PaymentStatus)
            $table->unsignedBigInteger('amount');            // sen. Copied from the order total on the server, never from the browser.
            $table->string('currency', 3)->default('MYR');
            $table->string('method', 32)->nullable();        // card, fpx, ewallet... as reported by the gateway
            $table->text('checkout_url')->nullable();        // where the customer goes to pay
            $table->string('failure_reason')->nullable();
            // Money arrived but the goods cannot be given (order already cancelled, or already paid by another attempt).
            $table->boolean('requires_refund')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
        // A gateway reference identifies exactly one payment.
        DB::statement('CREATE UNIQUE INDEX payments_provider_reference_unique ON payments (provider, provider_reference) WHERE provider_reference IS NOT NULL');
        // At most ONE open attempt per order, enforced by the database: two tabs or a double click cannot start two payments.
        DB::statement("CREATE UNIQUE INDEX payments_one_open_per_order ON payments (order_id) WHERE status IN ('PENDING', 'PROCESSING')");

        // The payment's diary: one row for everything that happened, never changed or removed afterwards.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);                     // INITIATED, SUCCEEDED, FAILED, ... (App\Enums\PaymentTransactionType)
            $table->string('status_before', 16)->nullable();
            $table->string('status_after', 16)->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('provider_event_id', 100)->nullable();
            $table->string('note')->nullable();
            $table->jsonb('payload')->nullable();           // what the gateway told us, for investigations
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'id']);
        });

        // Append-only is enforced by the database itself, not just by our code's good manners.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_transactions_are_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'payment_transactions is append-only: rows cannot be changed or deleted';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared('CREATE TRIGGER payment_transactions_no_update BEFORE UPDATE OR DELETE ON payment_transactions FOR EACH ROW EXECUTE FUNCTION payment_transactions_are_append_only()');

        // Every webhook we have accepted. The UNIQUE key is the idempotency guard: the same event can only be recorded
        // (and therefore only be acted on) once, however many times the gateway delivers it.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 100);
            $table->string('type', 40);
            $table->string('source', 12)->default('webhook'); // webhook | reconcile
            $table->jsonb('payload');
            $table->string('outcome', 24)->nullable();        // processed | ignored | amount_mismatch | late_payment
            $table->timestamp('received_at')->useCurrent();

            $table->unique(['provider', 'event_id']);
        });

        // The pretend payment company used for local development. It owns its own records, just like a real gateway
        // owns the payment pages. Never used when APP_ENV=production.
        Schema::create('fake_gateway_payments', function (Blueprint $table) {
            $table->string('reference', 60)->primary();
            $table->string('merchant_reference', 40);   // our payments.id
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->text('return_url');
            $table->string('status', 16)->default('pending'); // pending | succeeded | failed | cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_gateway_payments');
        Schema::dropIfExists('webhook_events');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_transactions_no_update ON payment_transactions');
        Schema::dropIfExists('payment_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS payment_transactions_are_append_only()');
        Schema::dropIfExists('payments');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
