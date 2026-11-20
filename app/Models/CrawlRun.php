<?php

namespace App\Models;

use Database\Factories\CrawlRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['crawl_target_id', 'active_target_id', 'status', 'settings', 'message', 'started_at', 'heartbeat_at', 'finished_at', 'retry_at'])]
class CrawlRun extends Model
{
    /** @use HasFactory<CrawlRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['settings' => 'array', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'finished_at' => 'datetime', 'retry_at' => 'datetime'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(CrawlTarget::class, 'crawl_target_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(CrawledPage::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running', 'paused'], true);
    }
}
