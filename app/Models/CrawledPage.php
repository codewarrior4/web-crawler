<?php

namespace App\Models;

use Database\Factories\CrawledPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['crawl_run_id', 'url', 'url_hash', 'final_url', 'depth', 'status', 'attempts', 'http_status', 'record_count', 'message'])]
class CrawledPage extends Model
{
    /** @use HasFactory<CrawledPageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['depth' => 'integer', 'attempts' => 'integer', 'record_count' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class, 'crawl_run_id');
    }
}
