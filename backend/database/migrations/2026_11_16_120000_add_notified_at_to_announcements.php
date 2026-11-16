<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Set once recipients have been notified; scheduled announcements stay null until published_at.
            $table->timestamp('notified_at')->nullable()->after('published_at');
        });
        // Everything that already exists has been sent.
        \Illuminate\Support\Facades\DB::table('announcements')->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
