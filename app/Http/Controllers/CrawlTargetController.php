<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCrawlTargetRequest;
use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use App\Models\ExtractedRecord;
use App\Services\Crawling\CrawlManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CrawlTargetController extends Controller
{
    public function index(): View
    {
        return view('targets.index', ['targets' => CrawlTarget::latest()->paginate(12), 'editing' => null,
            'stats' => ['targets' => CrawlTarget::count(), 'active' => CrawlRun::whereNotNull('active_target_id')->count(), 'leads' => ExtractedRecord::where('kind', 'business')->count(), 'jobs' => ExtractedRecord::where('kind', 'job')->count()],
            'runs' => CrawlRun::with('target')->latest()->limit(8)->get()]);
    }

    public function edit(CrawlTarget $target): View
    {
        return view('targets.edit', ['editing' => $target]);
    }

    public function store(SaveCrawlTargetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['next_crawl_at'] = $data['schedule_hours'] ? now()->addHours($data['schedule_hours']) : null;
        CrawlTarget::create($data);

        return redirect()->route('dashboard')->with('status', 'Target created. You can start its first crawl.');
    }

    public function update(SaveCrawlTargetRequest $request, CrawlTarget $target, CrawlManager $manager): RedirectResponse
    {
        foreach (CrawlRun::where('active_target_id', $target->id)->get() as $run) {
            $manager->cancel($run);
        }
        $data = $request->validated();
        $data['next_crawl_at'] = $data['schedule_hours'] && $data['is_enabled'] ? now()->addHours($data['schedule_hours']) : null;
        $target->update($data);

        return redirect()->route('dashboard')->with('status', 'Target updated. Any previous active run was cancelled.');
    }
}
