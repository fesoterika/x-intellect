@extends('layouts.site')

@section('title', ($query ? 'Поиск: '.$query : 'Поиск').' — X-Intellect')

@section('meta')
    <meta name="robots" content="noindex, follow">
@endsection

@section('content')
    <h1 class="page-title">Поиск по архиву</h1>

    <form action="{{ route('search') }}" method="GET" style="display: flex; gap: 10px; max-width: 560px; margin: 18px 0 30px;">
        <input type="search" name="q" value="{{ $query }}" placeholder="Например: Биоэкран, Посредники, Хроносфера…"
               style="flex: 1; background: var(--xi-surface); border: 1px solid var(--xi-line); border-radius: 999px; color: var(--xi-ink); padding: 11px 18px; font-size: 15px;">
        <button style="background: var(--xi-accent-deep); color: #fff; border: none; border-radius: 999px; padding: 11px 24px; font-weight: 600; cursor: pointer;">Найти</button>
    </form>

    @if ($query !== '')
        @if (! $results)
            <p style="color: var(--xi-ink-faint);">Введите не менее двух символов для поиска.</p>
        @elseif ($results->total() === 0)
            <h2 class="section-title">Ничего не найдено</h2>
        @else
            <h2 class="section-title">Найдено: {{ $results->total() }}</h2>

            <div style="display: grid; gap: 12px; max-width: 760px;">
                @foreach ($results as $page)
                    @include('site.partials.page-card', ['page' => $page])
                @endforeach
            </div>

            <div style="margin-top: 24px; max-width: 760px;">{{ $results->links('site.partials.pagination') }}</div>
        @endif
    @endif
@endsection
