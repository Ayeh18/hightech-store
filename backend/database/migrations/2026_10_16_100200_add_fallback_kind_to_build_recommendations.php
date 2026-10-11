<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The kind of reason the AI's suggestion was not used (App\Enums\RecommenderFailure), beside the full sentence
        // in fallback_reason: sentences differ by product and amount, kinds can be counted.
        Schema::table('build_recommendations', function (Blueprint $table) {
            $table->string('fallback_kind', 24)->nullable()->after('fallback_reason');
        });
    }

    public function down(): void
    {
        Schema::table('build_recommendations', function (Blueprint $table) {
            $table->dropColumn('fallback_kind');
        });
    }
};
