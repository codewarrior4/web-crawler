<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_operator')->default(false));
        Schema::table('crawl_targets', function (Blueprint $table) {
            $table->unsignedSmallInteger('schedule_hours')->nullable();
            $table->timestamp('next_crawl_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_targets', function (Blueprint $table) {
            $table->dropIndex(['next_crawl_at']);
            $table->dropColumn(['schedule_hours', 'next_crawl_at']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_operator'));
    }
};
