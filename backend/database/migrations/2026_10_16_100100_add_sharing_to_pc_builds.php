<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A saved build is private. Its owner can hand out a link to it: the link holds this long random token, not the
        // build's id, so it cannot be guessed, and clearing the token switches the link off for good.
        Schema::table('pc_builds', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pc_builds', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'shared_at']);
        });
    }
};
