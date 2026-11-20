<?php

namespace App\Models;

use Database\Factories\ExtractedRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['crawl_target_id', 'crawl_run_id', 'kind', 'identity_key', 'title', 'source_url', 'data', 'search_text', 'last_seen_at', 'expires_at'])]
class ExtractedRecord extends Model
{
    /** @use HasFactory<ExtractedRecordFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['data' => 'array', 'last_seen_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(CrawlTarget::class, 'crawl_target_id');
    }
}
