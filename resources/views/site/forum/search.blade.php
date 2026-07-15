@extends('layouts.site')

@section('title', ($query !== '' ? '«'.$query.'» — ' : '').'Поиск по архиву форума X-Intellect')

@section('meta')
    {{-- Служебная страница поиска — не для индекса --}}
    <meta name="robots" content="noindex, follow">
    <meta name="description" content="Поиск по темам архивного форума X-Intellect: по заголовкам и содержанию сообщений.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/forum">
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        'Архив форума' => '/forum',
        'Поиск' => null,
    ]])

    <h1 class="page-title">Поиск по форуму</h1>
    <hr class="title-rule" aria-hidden="true">

    @include('site.partials.forum-disclaimer')

    @include('site.forum.search-box', ['query' => $query, 'live' => false])

    @if (mb_strlen($query) >= 2)
        @include('site.forum.results')
    @elseif ($query !== '')
        <p class="forum-search-empty">Введите не менее двух символов.</p>
    @endif
@endsection
