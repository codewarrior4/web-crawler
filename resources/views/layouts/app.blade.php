<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Workspace') · Scout</title>
    @yield('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('home') }}"><span class="brand-icon">s</span> scout<span class="brand-dot">.</span></a>
        <p class="nav-label">CRAWLER WORKSPACE</p>
        <nav aria-label="Main navigation">
            <a class="{{ request()->routeIs('dashboard', 'targets.*') ? 'selected' : '' }}" href="{{ route('dashboard') }}"><span>◈</span> Overview & targets</a>
            <a class="{{ request()->routeIs('runs.*') ? 'selected' : '' }}" href="{{ route('runs.index') }}"><span>↗</span> Crawl history</a>
            <a class="{{ request()->routeIs('results.*') ? 'selected' : '' }}" href="{{ route('results.index') }}"><span>▦</span> Collected results</a>
        </nav>
        <div class="sidebar-bottom"><span class="live-dot"></span> Public web. Useful signals.<br><small>Bounded crawls · Source-backed data</small></div>
    </aside>
    <div class="workspace">
        <header class="topbar"><span>Workspace <span class="muted">/</span> @yield('title', 'Overview')</span><div class="operator">{{ auth()->user()->name }}<form action="{{ route('logout') }}" method="POST">@csrf<button class="text-button">Sign out</button></form></div></header>
        <main class="content">
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="errors" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</div>
</body></html>
