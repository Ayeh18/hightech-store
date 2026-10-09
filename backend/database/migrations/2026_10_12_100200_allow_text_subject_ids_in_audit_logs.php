<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Audit entries about orders and saved builds refer to ULIDs (text), not numbers. The column becomes text so
    // every kind of record can be audited; existing numeric ids are kept as their text form.
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN subject_id TYPE varchar(40) USING subject_id::varchar');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN subject_id TYPE bigint USING subject_id::bigint');
    }
};
