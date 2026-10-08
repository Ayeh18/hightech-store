<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row of SETTINGS per rule class: on/off, how serious it is, and tunable numbers.
        // The rule's logic lives in code (app/Services/Compatibility/Rules); a missing row means "use the defaults".
        Schema::create('compatibility_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->string('severity', 8); // error | warning
            $table->jsonb('params')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compatibility_rules');
    }
};
