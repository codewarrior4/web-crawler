<?php

namespace App\Http\Controllers;

use App\Models\CrawlTarget;
use App\Models\ExtractedRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultController extends Controller
{
    private function filtered(Request $request): Builder
    {
        $filters = $request->validate(['kind' => ['nullable', Rule::in(['business', 'job'])], 'q' => ['nullable', 'string', 'max:200'],
            'target' => ['nullable', 'integer', 'exists:crawl_targets,id'], 'expiry' => ['nullable', Rule::in(['active', 'expired'])]]);
        $query = ExtractedRecord::query();
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }
        if (! empty($filters['target'])) {
            $query->where('crawl_target_id', $filters['target']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%']);
        }
        if (($filters['expiry'] ?? '') === 'expired') {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        } elseif (($filters['expiry'] ?? '') === 'active') {
            $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return $query;
    }

    public function index(Request $request): View
    {
        return view('results.index', ['records' => $this->filtered($request)->with('target')->latest('last_seen_at')->paginate(20)->withQueryString(), 'targets' => CrawlTarget::orderBy('name')->get()]);
    }

    public function show(ExtractedRecord $record): View
    {
        return view('results.show', ['record' => $record->load('target')]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Type', 'Title', 'Company', 'Website / application URL', 'Emails', 'Phones', 'Address / location', 'Employment type', 'Posted at', 'Expires at', 'Salary', 'Description', 'Source', 'Last seen'], escape: '');
            foreach ($query->lazyById(200) as $record) {
                $data = $record->data;
                $row = [$record->kind, $record->title, $data['company'] ?? '', $data['website'] ?? $data['application_url'] ?? '', implode('; ', $data['emails'] ?? []),
                    implode('; ', $data['phones'] ?? []), $data['address'] ?? $data['location'] ?? '', $data['employment_type'] ?? '', $data['posted_at'] ?? '',
                    $record->expires_at?->toIso8601String() ?? '', $data['salary'] ?? '', $data['description'] ?? '', $record->source_url, $record->last_seen_at->toIso8601String()];
                $row = array_map(fn ($value): string => preg_match('/^[\s\x00-\x1f]*[=+\-@]|^[\t\r\n]/u', (string) $value) ? "'".$value : (string) $value, $row);
                fputcsv($file, $row, escape: '');
            }
            fclose($file);
        }, 'crawler-results-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
