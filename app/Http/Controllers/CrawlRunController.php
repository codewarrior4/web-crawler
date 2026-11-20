<?php

namespace App\Http\Controllers;

use App\Models\CrawlRun;
use App\Models\CrawlTarget;
use App\Services\Crawling\CrawlManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CrawlRunController extends Controller
{
    public function index(): View
    {
        return view('runs.index', ['runs' => CrawlRun::with('target')->latest()->paginate(20)]);
    }

    public function store(CrawlTarget $target, CrawlManager $manager): RedirectResponse
    {
        $run = $manager->start($target);

        return redirect()->route('runs.show', $run)->with('status', 'Crawl queued. Existing active runs are reused.');
    }

    public function show(CrawlRun $run): View
    {
        $run->load('target');

        return view('runs.show', ['run' => $run, 'pages' => $run->pages()->orderBy('id')->paginate(30),
            'counts' => $run->pages()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function destroy(CrawlRun $run, CrawlManager $manager): RedirectResponse
    {
        $manager->cancel($run);

        return back()->with('status', 'Crawl cancelled. An in-flight request may finish; its results will be discarded.');
    }
}
