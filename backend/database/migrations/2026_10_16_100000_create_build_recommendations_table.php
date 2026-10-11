<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per build the shop suggested: what was asked for, who suggested it (rules or AI) and whether the
        // customer took it. It is a record for the shop's own insights; nothing reads it to make a decision.
        Schema::create('build_recommendations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // guests can ask too
            $table->unsignedBigInteger('budget');             // sen
            $table->string('purpose', 16);
            $table->string('priority', 16);
            $table->string('resolution', 8);
            $table->json('games')->nullable();
            $table->string('source', 8);                      // rules | ai
            $table->string('fallback_reason')->nullable();    // why the AI's suggestion was not used, when it was asked
            $table->unsignedBigInteger('total');              // sen, at the prices of that moment
            $table->json('selection');                        // slot => product id
            $table->timestamp('accepted_at')->nullable();     // the customer loaded it into the builder
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_recommendations');
    }
};
