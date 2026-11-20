@extends('layouts.app')
@section('title', 'Edit target')
@section('content')<div class="page-heading"><div><a class="muted" href="{{ route('dashboard') }}">← Back to overview</a><h1>Edit {{ $editing->name }}</h1><p class="muted">Saving changes cancels any active run so the next crawl uses these settings.</p></div></div><div class="panel new-target">@include('targets.form')</div>@endsection
