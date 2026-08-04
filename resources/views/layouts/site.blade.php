<!DOCTYPE html>
{{-- Класс xi-no-alpine стоит в разметке ИЗНАЧАЛЬНО: это базовое состояние для
     всех, у кого не выполнится собранный Vite бандл (правила деградации меню и
     плеера — в конце app.css). Скрипт ниже снимает его у браузеров, где бандл
     точно поедет. Так «JS выключен совсем» работает без отдельного <noscript>. --}}
<html lang="ru" class="xi-no-alpine">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Тема применяется до рендера, чтобы не было вспышки (FOUC). По умолчанию светлая. --}}
    <script>
        (function () {
            try {
                document.documentElement.setAttribute('data-theme', localStorage.getItem('xi-theme') || 'light');
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    {{-- Сторож совместимости. Vite собирает только ES-модули, а <script
         type="module"> появился в Safari 10.1 / iOS 10.3 — на Safari 9-10.0
         бандл не выполняется вовсе: меню на смартфоне не открыть, а плеер
         остаётся пустым <audio> без src (источник ему ставит Alpine).

         Код намеренно на ES5 — ни стрелок, ни const, ни шаблонных строк:
         иначе он сам упадёт ровно там, где нужен. --}}
    <script>
        (function () {
            var html = document.documentElement;
            var MARK = 'xi-no-alpine';

            function setMark(on) {
                var padded = ' ' + html.className + ' ';
                var has = padded.indexOf(' ' + MARK + ' ') !== -1;

                if (on && !has) html.className = html.className + ' ' + MARK;
                if (!on && has) html.className = padded.split(' ' + MARK + ' ').join(' ').replace(/^\s+|\s+$/g, '');
            }

            // Браузер понимает модули - бандл поедет, снимаем метку СИНХРОННО,
            // до первой отрисовки, чтобы запасной вариант нигде не мелькнул.
            // Safari 10.1 - известное исключение: модули есть, а свойства
            // noModule нет, поэтому там метку снимет уже проверка ниже.
            if ('noModule' in HTMLScriptElement.prototype) setMark(false);

            // Метка возвращается, если Alpine так и не поднялся: бандл не
            // загрузился, упал по дороге или это старый Safari. Проверяем и
            // дальше - на медленной связи бандл может приехать позже.
            var waited = 0;

            function tick() {
                if (html.getAttribute('data-alpine') === 'ready') { setMark(false); return; }

                waited += 250;
                if (waited >= 1200) setMark(true); // фора на загрузку и старт
                if (waited < 15000) window.setTimeout(tick, 250);
            }

            window.setTimeout(tick, 250);

            // Возраст браузера определяем по поддержке `inset`: оно приехало
            // в Safari 14.1 — в том же выпуске, что и flexbox-gap. Проверять
            // сам gap через @supports нельзя (`@supports (gap: 1px)` истинен
            // уже в Safari 10, потому что gap работает в grid), а замер живым
            // элементом требует <body> и успевает только к DOMContentLoaded —
            // то есть ПОЗЖЕ модульного бандла, который к тому моменту уже
            // запустил фон. CSS.supports синхронен и работает с Safari 9.
            //
            // Неточность: Chrome 84-86 и Firefox 63-65 умеют gap, но ещё не
            // знают inset — они получат отступы на полях вместо gap. Разница
            // незаметна, зато нет гонки и лишнего пересчёта вёрстки.
            var modern = window.CSS && window.CSS.supports && window.CSS.supports('inset', '0');

            if (!modern) {
                // xi-no-flexgap — отступы на полях вместо gap (правила в app.css)
                // xi-legacy    — выключает анимации фона; стартовать им не даёт
                //                и сам starfield.js, читая этот же класс
                html.className = html.className + ' xi-no-flexgap xi-legacy';
            }
        })();
    </script>

    <title>@yield('title', 'X-Intellect - архив проекта «Сфера Разума» / X-Интеллект')</title>
    @hasSection('meta')
        @yield('meta')
    @else
        <meta name="description" content="Архив проекта X-Intellect (ранее - «Сфера Разума»): вики, библиотека, записи курсов А. Глаза, материалы о контактах с Внеземным Разумом.">
    @endif

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/favicon.svg">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="site-body">
    <div class="starfield" aria-hidden="true"></div>
    {{-- Светлая тема: «живая аура» — три пятна, сближающихся без слияния;
         span — внешняя «орбита», i — само пятно (см. .light-aura в app.css) --}}
    <div class="light-aura" aria-hidden="true"><span><i></i></span><span><i></i></span><span><i></i></span></div>

    {{-- Напоминание редактору: посетители сейчас видят заглушку техработ --}}
    @if (auth()->user()?->isEditor() && \App\Models\Setting::maintenanceEnabled())
        <div class="xi-maint-banner">
            Включён режим технических работ - посетители видят заглушку, вы просматриваете сайт как редактор.
            <a href="{{ route('admin.dashboard') }}">Управление</a>
        </div>
    @endif

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
                    X-Intellect.org
                    <span class="tagline">новый сайт проекта · архив</span>
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
                    @if ($item->children->isNotEmpty())
                        {{-- Пункт с подменю: на ПК раскрывается по наведению (CSS :hover),
                             на смартфоне/планшете - по тапу на стрелку (Alpine) --}}
                        <div class="nav-item" x-data="{ subOpen: false }" @click.outside="subOpen = false">
                            <span class="nav-item-row">
                                <a href="{{ $item->url }}" @class(['active' => request()->is(ltrim($item->url, '/').'*') && $item->url !== '/'])>{{ $item->label }}</a>
                                <button type="button" class="nav-caret" @click.prevent="subOpen = !subOpen"
                                        :aria-expanded="subOpen.toString()" aria-label="Подменю «{{ $item->label }}»">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                            </span>
                            <div class="nav-submenu" :class="{ 'is-open': subOpen }">
                                @foreach ($item->children as $child)
                                    <a href="{{ $child->url }}" @class(['active' => request()->is(ltrim($child->url, '/').'*')])>{{ $child->label }}</a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <a href="{{ $item->url }}" @class(['active' => request()->is(ltrim($item->url, '/').'*') && $item->url !== '/'])>{{ $item->label }}</a>
                    @endif
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

        {{-- Подложка: клик по свободному пространству под меню закрывает его
             (только на узких экранах; на десктопе скрыта через CSS) --}}
        <div class="site-nav-backdrop" x-show="menuOpen" x-cloak @click="menuOpen = false" aria-hidden="true"></div>
    </header>

    <main class="site-wrap" style="padding-top: 36px; padding-bottom: 20px;">
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="site-footer-inner">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <nav aria-label="Футер">
                    @foreach ($footerMenu ?? [] as $item)
                        <a href="{{ $item->url }}">{{ $item->label }}</a>
                    @endforeach
                </nav>

                {{-- Переключатель темы: значение хранится в localStorage
                     (не cookie), тема применяется мгновенно к <html> --}}
                <button type="button" class="theme-toggle"
                        x-data="{ theme: document.documentElement.getAttribute('data-theme') || 'light' }"
                        @click="theme = (theme === 'dark' ? 'light' : 'dark');
                                document.documentElement.setAttribute('data-theme', theme);
                                try { localStorage.setItem('xi-theme', theme); } catch (e) {}
                                $dispatch('xi-theme', theme)"
                        @xi-theme.window="theme = $event.detail"
                        :aria-label="theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему'">
                    <template x-if="theme === 'dark'">
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                            Светлая тема
                        </span>
                    </template>
                    <template x-if="theme === 'light'">
                        <span style="display:inline-flex; align-items:center; gap:8px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                            Тёмная тема
                        </span>
                    </template>
                </button>
            </div>

            {{-- Юридический дисклеймер - обязателен на всех страницах;
                 полная версия — на странице «Правовая информация» --}}
            <p class="legal">
                Некоммерческий архив материалов проекта «Икс-Интеллект» (X-Intellect).
                Владелец сайта не является автором проекта; права на материалы принадлежат их авторам.
                Материалы публикуются в информационных и архивных целях.
                <a href="{{ url('/rules/pravovaia-informaciia') }}">Правовая информация</a>
            </p>

            <p>© 2012-{{ date('Y') }} X-Intellect.org · Создатель нового сайта - <a href="{{ route('fesoterika') }}">Ф. (@fesoterika)</a></p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
