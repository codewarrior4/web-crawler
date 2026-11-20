<?php

use App\Http\Controllers\CrawlRunController;
use App\Http\Controllers\CrawlTargetController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'can:manage-crawler'])->group(function () {
    Route::get('/dashboard', [CrawlTargetController::class, 'index'])->name('dashboard');
    Route::post('/targets', [CrawlTargetController::class, 'store'])->name('targets.store');
    Route::get('/targets/{target}/edit', [CrawlTargetController::class, 'edit'])->name('targets.edit');
    Route::put('/targets/{target}', [CrawlTargetController::class, 'update'])->name('targets.update');
    Route::post('/targets/{target}/runs', [CrawlRunController::class, 'store'])->middleware('throttle:10,1')->name('runs.store');
    Route::get('/runs', [CrawlRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/{run}', [CrawlRunController::class, 'show'])->name('runs.show');
    Route::delete('/runs/{run}', [CrawlRunController::class, 'destroy'])->name('runs.destroy');
    Route::get('/results', [ResultController::class, 'index'])->name('results.index');
    Route::get('/results/export', [ResultController::class, 'export'])->middleware('throttle:10,1')->name('results.export');
    Route::get('/results/{record}', [ResultController::class, 'show'])->name('results.show');
});
