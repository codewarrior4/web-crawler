<?php

namespace App\Services\Crawling;

use App\Exceptions\FetchException;
use App\Jobs\ProcessCrawl;
use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use App\Models\ExtractedRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrawlManager
{
    public function __construct(private PageFetcher $fetcher, private LinkDiscovery $links, private ContentExtractor $extractor) {}

    public function start(CrawlTarget $target): CrawlRun
    {
        $created = false;
        $run = DB::transaction(function () use ($target, &$created): CrawlRun {
            $target = CrawlTarget::lockForUpdate()->findOrFail($target->id);
            if (! $target->is_enabled) {
                throw ValidationException::withMessages(['target' => 'Enable this target before starting a crawl.']);
            }
            $existing = CrawlRun::where('active_target_id', $target->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $url = $this->links->normalize($target->url, $target->url);
            if ($url === null) {
                throw ValidationException::withMessages(['url' => 'The target URL is invalid.']);
            }
            $settings = $target->only(['name', 'url', 'type', 'is_enabled', 'max_pages', 'max_depth']);
            $settings['url'] = $url;
            $run = CrawlRun::create(['crawl_target_id' => $target->id, 'active_target_id' => $target->id, 'status' => 'pending', 'settings' => $settings]);
            $run->pages()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'depth' => 0]);
            $created = true;

            return $run;
        });
        if ($created) {
            ProcessCrawl::dispatch($run->id);
        }

        return $run;
    }

    public function cancel(CrawlRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run = CrawlRun::lockForUpdate()->findOrFail($run->id);
            if ($run->isActive()) {
                $this->finish($run, 'cancelled', 'Cancelled by an operator.');
            }
        });
    }

    public function finish(CrawlRun $run, string $status, ?string $message = null): void
    {
        $changed = CrawlRun::whereKey($run->id)->whereNotNull('active_target_id')->update(['status' => $status, 'active_target_id' => null, 'message' => $message, 'finished_at' => now(), 'retry_at' => null]);
        if ($changed) {
            $run->pages()->whereIn('status', ['pending', 'running'])->update(['status' => 'skipped', 'message' => $message ?? 'Run finished.']);
        }
    }

    public function process(int $id): bool
    {
        $lock = Cache::lock('crawler:run:'.$id, 180);
        if (! $lock->get()) {
            return false;
        }
        try {
            $run = CrawlRun::with('target')->find($id);
            if ($run === null || ! $run->isActive()) {
                return false;
            }
            if (! $run->target->is_enabled) {
                $this->finish($run, 'cancelled', 'Target was disabled.');

                return false;
            }
            if ($run->retry_at?->isFuture()) {
                return false;
            }
            if ($run->started_at === null) {
                $run->update(['started_at' => now()]);
            }
            $deadline = $run->started_at->timestamp + config('crawler.run_budget');
            if (now()->timestamp >= $deadline) {
                $this->finish($run, 'failed', 'The ten-minute crawl budget was exhausted.');

                return false;
            }
            $run->update(['status' => 'running', 'heartbeat_at' => now(), 'retry_at' => null]);
            $page = $run->pages()->whereIn('status', ['running', 'pending'])->orderBy('depth')->orderBy('id')->first();
            if ($page === null) {
                $status = $run->pages()->where('status', 'fetched')->exists() ? 'completed' : 'failed';
                $this->finish($run, $status, $status === 'failed' ? 'No pages could be fetched. See page details.' : null);

                return false;
            }
            if ($run->pages()->where('status', 'fetched')->where('final_url', $page->url)->exists()) {
                $page->update(['status' => 'skipped', 'message' => 'Already fetched through a redirect.']);

                return true;
            }
            if ($page->attempts >= 3) {
                $page->update(['status' => 'failed', 'message' => 'Page recovery attempts exhausted.']);

                return true;
            }
            $page->update(['status' => 'running', 'attempts' => $page->attempts + 1]);
            $snapshot = new CrawlTarget($run->settings);
            try {
                $fetched = $this->fetcher->fetch($snapshot, $page->url, $deadline);
            } catch (FetchException $exception) {
                $run->refresh();
                if (! $run->isActive()) {
                    return false;
                }
                if ($exception->retryAfter !== null && $page->attempts < 3) {
                    $page->update(['status' => 'pending', 'message' => $exception->getMessage()]);
                    $run->update(['status' => 'paused', 'message' => $exception->getMessage(), 'retry_at' => now()->addSeconds($exception->retryAfter)]);

                    return false;
                }
                $blocked = str_contains($exception->getMessage(), 'disallowed') || str_contains($exception->getMessage(), 'blocked') || str_contains($exception->getMessage(), 'hostname');
                $page->update(['status' => $blocked ? 'skipped' : 'failed', 'message' => $exception->getMessage()]);

                return true;
            }
            $records = $this->extractor->extract($fetched['html'], $fetched['url'], $snapshot->type);
            $urls = $page->depth < $snapshot->max_depth ? $this->links->links($fetched['html'], $fetched['url'], (int) config('crawler.max_pending_urls')) : [];
            DB::transaction(function () use ($run, $page, $fetched, $records, $urls, $snapshot): void {
                $run = CrawlRun::lockForUpdate()->findOrFail($run->id);
                if (! $run->isActive()) {
                    return;
                }
                foreach ($records as $record) {
                    $existing = ExtractedRecord::firstOrNew(['crawl_target_id' => $run->crawl_target_id, 'identity_key' => $record['identity_key']]);
                    $data = $record['data'];
                    if ($existing->exists) {
                        foreach (['emails', 'phones', 'source_urls'] as $field) {
                            if (isset($data[$field])) {
                                $data[$field] = array_slice(array_values(array_unique(array_merge($existing->data[$field] ?? [], $data[$field]))), 0, 100);
                            }
                        }
                    }
                    $existing->fill(['crawl_run_id' => $run->id, 'kind' => $record['kind'], 'title' => $record['title'], 'source_url' => $fetched['url'], 'data' => $data,
                        'search_text' => mb_substr(implode(' ', array_filter(array_map(fn ($value): string => is_scalar($value) ? (string) $value : implode(' ', array_filter((array) $value, 'is_scalar')), $data))), 0, 20000),
                        'last_seen_at' => now(), 'expires_at' => $record['expires_at']])->save();
                }
                $page->update(['status' => 'fetched', 'final_url' => $fetched['url'], 'http_status' => $fetched['status'], 'record_count' => count($records), 'message' => null]);
                $known = $run->pages()->pluck('url_hash')->flip()->all();
                foreach ($run->pages()->whereNotNull('final_url')->pluck('final_url') as $previousUrl) {
                    $known[hash('sha256', $previousUrl)] = true;
                }
                $final = $this->links->normalize($fetched['url'], $snapshot->url) ?? $fetched['url'];
                $known[hash('sha256', $final)] = true;
                $pending = $run->pages()->where('status', 'pending')->count();
                $total = $run->pages()->count();
                foreach ($urls as $url) {
                    if ($total >= $snapshot->max_pages || $pending >= config('crawler.max_pending_urls')) {
                        break;
                    }
                    if ($snapshot->type === 'job') {
                        $prefix = rtrim(parse_url($snapshot->url, PHP_URL_PATH) ?: '/', '/').'/';
                        if (! str_starts_with((parse_url($url, PHP_URL_PATH) ?: '/').'/', $prefix)) {
                            continue;
                        }
                    }
                    $hash = hash('sha256', $url);
                    if (isset($known[$hash])) {
                        continue;
                    }
                    $run->pages()->create(['url' => $url, 'url_hash' => $hash, 'depth' => $page->depth + 1]);
                    $known[$hash] = true;
                    $pending++;
                    $total++;
                }
                $run->update(['heartbeat_at' => now(), 'message' => null]);
            });

            return $run->fresh()->isActive();
        } finally {
            $lock->release();
        }
    }
}
