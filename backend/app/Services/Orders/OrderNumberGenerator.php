<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;

/** ORD-2026-00001, ORD-2026-00002, ... One counter per year, advanced by a single atomic statement. */
class OrderNumberGenerator
{
    public function next(): string
    {
        $year = (int) now()->format('Y');

        // INSERT ... ON CONFLICT DO UPDATE ... RETURNING is atomic, so two checkouts at the same moment can never
        // receive the same number. If the surrounding transaction rolls back, the increment rolls back too (no gaps).
        $row = DB::selectOne(
            'INSERT INTO order_number_sequences (year, last_number) VALUES (?, 1)
             ON CONFLICT (year) DO UPDATE SET last_number = order_number_sequences.last_number + 1
             RETURNING last_number',
            [$year],
        );

        return sprintf('ORD-%d-%05d', $year, $row->last_number);
    }
}
