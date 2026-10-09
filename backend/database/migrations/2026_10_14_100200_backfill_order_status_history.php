<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders that existed before the status history was introduced have no history, so their timeline would be empty.
 * Give each a baseline: "placed" at its creation time and, if it has moved on, its current status at the best date we
 * know. Steps in between (e.g. PROCESSING) cannot be known, and are not invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO order_status_histories (order_id, from_status, to_status, user_id, created_at)
            SELECT o.id, NULL, 'PENDING_PAYMENT', NULL, o.created_at
            FROM orders o
            WHERE NOT EXISTS (SELECT 1 FROM order_status_histories h WHERE h.order_id = o.id)
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO order_status_histories (order_id, from_status, to_status, user_id, created_at)
            SELECT o.id, 'PENDING_PAYMENT', o.status, NULL, COALESCE(o.paid_at, o.cancelled_at, o.updated_at)
            FROM orders o
            WHERE o.status <> 'PENDING_PAYMENT'
              AND (SELECT count(*) FROM order_status_histories h WHERE h.order_id = o.id) = 1
        SQL);
    }

    public function down(): void
    {
        // Nothing to undo: the rows are harmless history.
    }
};
