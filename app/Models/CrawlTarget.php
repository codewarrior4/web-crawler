<?php

namespace App\Models;

use Database\Factories\CrawlTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'url', 'type', 'is_enabled', 'max_pages', 'max_depth'])]
class CrawlTarget extends Model
{
    /** @use HasFactory<CrawlTargetFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'max_pages' => 'integer',
            'max_depth' => 'integer',
        ];
    }
}
