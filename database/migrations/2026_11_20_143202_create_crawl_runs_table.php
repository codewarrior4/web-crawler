<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('active_target_id')->nullable()->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->json('settings');
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
        });
        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_run_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->text('final_url')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['crawl_run_id', 'url_hash']);
            $table->index(['crawl_run_id', 'status']);
        });
        Schema::create('extracted_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crawl_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->char('identity_key', 64);
            $table->string('title');
            $table->text('source_url');
            $table->json('data');
            $table->text('search_text');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['crawl_target_id', 'identity_key']);
            $table->index(['kind', 'crawl_target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_records');
        Schema::dropIfExists('crawled_pages');
        Schema::dropIfExists('crawl_runs');
    }
};
