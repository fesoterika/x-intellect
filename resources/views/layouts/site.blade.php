<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'X-Intellect — архив проекта «Сфера Разума» / X-Интеллект')</title>
    @hasSection('meta')
        @yield('meta')
    @else
        <meta name="description" content="Архив проекта X-Intellect (ранее — «Сфера Разума»): вики, библиотека, записи курсов А. Глаза, материалы о контактах с Внеземным Разумом.">
    @endif

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/favicon.svg">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="site-body">
    <div class="starfield" aria-hidden="true"></div>

    <header class="site-header" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
        <div class="site-header-inner">
            <a class="site-logo" href="{{ route('home') }}">
                <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="X-Intellect">
                    <rect fill="#fff" x="2" y="2" width="95.9" height="95.9" rx="8.4" ry="8.4"/>
                    <g opacity=".9"><ellipse fill="none" stroke="#6667ab" stroke-width="5" cx="49.9" cy="50" rx="35.9" ry="13.5"/></g>
                    <g>
                        <line stroke="#40334f" stroke-width="2" x1="31.4" y1="17.1" x2="68.6" y2="82.9"/>
                        <rect fill="#40334f" stroke="#40334f" stroke-width="2" x="45.9" y="12.2" width="8.2" height="75.5" transform="translate(-18.1 31.1) rotate(-29.5)"/>
                    </g>
                    <g>
                        <line stroke="#40334f" stroke-width="2" x1="68.6" y1="17.1" x2="31.4" y2="82.9"/>
                        <rect fill="#40334f" stroke="#40334f" stroke-width="2" x="12.2" y="45.9" width="75.5" height="8.2" transform="translate(-18.1 68.9) rotate(-60.5)"/>
                    </g>
                    <circle fill="none" stroke="#6667ab" stroke-width="5" cx="50" cy="50" r="37.9"/>
                    <circle fill="#5f4c79" cx="50" cy="13.1" r="8.4"/>
                </svg>
                <span>
                    X-Intellect
                    <span class="tagline">архив проекта · с 2012 года</span>
                </span>
            </a>

            {{-- Гамбургер: виден только на узких экранах (см. CSS .site-burger) --}}
            <button type="button" class="site-burger" @click="menuOpen = !menuOpen"
                    :aria-expanded="menuOpen.toString()" aria-controls="site-nav" aria-label="Меню">
                <svg x-show="!menuOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
                <svg x-show="menuOpen" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/>
                </svg>
            </button>

            <nav id="site-nav" class="site-nav" :class="{ 'is-open': menuOpen }" aria-label="Основная навигация">
                @foreach ($headerMenu ?? [] as $item)
                    <a href="{{ $item->url }}" @class(['active' => request()->is(ltrim($item->url, '/').'*') && $item->url !== '/'])>{{ $item->label }}</a>
                @endforeach

                <form class="site-search site-search--mobile" action="{{ route('search') }}" method="GET" role="search">
                    <svg class="site-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Поиск по архиву…" aria-label="Поиск">
                </form>
            </nav>

            <form class="site-search site-search--desktop" action="{{ route('search') }}" method="GET" role="search">
                <svg class="site-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Поиск по архиву…" aria-label="Поиск">
            </form>
        </div>
    </header>

    <main class="site-wrap" style="padding-top: 36px; padding-bottom: 20px;">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="site-footer-inner">
            <nav aria-label="Футер">
                @foreach ($footerMenu ?? [] as $item)
                    <a href="{{ $item->url }}">{{ $item->label }}</a>
                @endforeach
            </nav>

            {{-- Юридический дисклеймер с заглушки — обязателен на всех страницах --}}
            <p class="legal">
                Владелец этого сайта не является автором проекта X-Intellect («Икс-Интеллект»)
                и не несёт ответственности за содержание размещённых материалов.
                Все материалы представлены исключительно в ознакомительных и архивных целях.
            </p>

            <p>© 2012–{{ date('Y') }} X-Intellect · Создатель нового сайта — <a href="{{ route('fesoterika') }}">Ф. (@fesoterika)</a></p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
