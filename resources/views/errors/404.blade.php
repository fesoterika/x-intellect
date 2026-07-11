@extends('layouts.site')

@section('title', 'Страница не найдена (404) — X-Intellect')

@section('meta')
    {{-- 404 не индексируется и не попадает в выдачу; статус-код отдаёт Laravel --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Запрошенная страница не найдена в архиве X-Intellect. Вернитесь на главную или воспользуйтесь поиском по архиву.">
@endsection

@section('content')
    <section class="xi-404" role="main">
        <p class="xi-404__code" aria-hidden="true">404</p>
        <h1 class="page-title">Страница не найдена</h1>
        <hr class="title-rule" aria-hidden="true">

        <p class="xi-404__text">
            Такой страницы в архиве нет - возможно, ссылка устарела или в адресе опечатка.
            Вернитесь на главную или воспользуйтесь поиском по архиву.
        </p>

        <div class="xi-404__actions">
            <a class="xi-404__home" href="{{ route('home') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 11l9-8 9 8"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>
                </svg>
                На главную
            </a>

            <form class="site-search xi-404__search" action="{{ route('search') }}" method="GET" role="search">
                <svg class="site-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="search" name="q" placeholder="Поиск по архиву…" aria-label="Поиск по архиву">
            </form>
        </div>

        <p class="xi-404__hint">
            Ищете конкретный материал? Загляните в <a href="/wiki">Вики</a>,
            <a href="/library">Библиотеку</a> или <a href="/glossary">Глоссарий</a>.
        </p>
    </section>
@endsection
