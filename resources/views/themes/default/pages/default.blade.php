@extends('layouts.app')

@section('content')
    <article class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-semibold tracking-tight">{{ $page->t('title') }}</h1>

        @if ($body = $page->t('body'))
            <div class="prose mt-8 max-w-none">{!! $body !!}</div>
        @endif
    </article>
@endsection
