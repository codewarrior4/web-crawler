<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->string('type', 20);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('max_pages')->default(25);
            $table->unsignedTinyInteger('max_depth')->default(2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_targets');
    }
};
