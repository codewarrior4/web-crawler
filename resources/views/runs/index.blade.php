@extends('layouts.app')
@section('title', 'Crawl history')
@section('content')<div class="page-heading"><div><span class="eyebrow">EVERY RUN, ACCOUNTED FOR</span><h1>Crawl history</h1><p class="muted">Follow progress, inspect errors, and trace what happened.</p></div></div><div class="panel table-wrap">@include('runs.table')</div>{{ $runs->links() }}@endsection
