{{--
    Заглушка режима технических работ. Отдаётся middleware MaintenanceMode
    со статусом 503 + Retry-After (и автоматически используется `php artisan down`).
    Самостоятельная страница без сайтового меню: во время работ все ссылки
    навигации всё равно вели бы на эту же заглушку.
--}}
<!DOCTYPE html>
<html lang="ru">
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

    <title>Технические работы - X-Intellect</title>
    {{-- Статус 503 сам по себе не индексируется; noindex - подстраховка от промежуточных кешей --}}
    <meta name="robots" content="noindex">
    <meta name="description" content="На сайте X-Intellect ведутся технические работы. Публичная часть временно недоступна. Анонсы работ и даты окончания - в телеграм-канале.">

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="apple-touch-icon" href="/favicon.svg">

    @vite(['resources/css/app.css'])
</head>
<body class="site-body">
    <div class="starfield" aria-hidden="true"></div>
    {{-- Светлая тема: «живая аура» — как в layouts/site (см. .light-aura в app.css) --}}
    <div class="light-aura" aria-hidden="true"><span><i></i></span><span><i></i></span><span><i></i></span></div>

    <main class="xi-maint" role="main">
        <div class="xi-maint__logo" aria-hidden="true">
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
        </div>

        <p class="xi-maint__badge">Технические работы</p>

        <h1 class="page-title">Сайт скоро вернётся</h1>
        <hr class="title-rule" aria-hidden="true">

        <p class="xi-maint__text">
            На X-Intellect.org ведутся технические работы - мы обновляем архив проекта.
            Публичная часть сайта временно недоступна для посетителей.
        </p>

        <p class="xi-maint__text">
            Анонсы технических работ и даты их окончания публикуются в телеграм-канале:
        </p>

        <a class="xi-maint__tg" href="https://t.me/+H15kvUCtrUw4ODAy" rel="noopener">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M21.94 4.14a1.5 1.5 0 0 0-2.05-1.7L2.9 9.4c-1.24.5-1.16 2.3.12 2.68l4.66 1.38 1.75 5.62c.36 1.14 1.8 1.47 2.6.6l2.5-2.72 4.63 3.4c.9.67 2.2.17 2.42-.94l2.36-15.28ZM8.61 12.44l9.02-5.7c.4-.25.8.3.46.62l-6.87 6.42a2 2 0 0 0-.62 1.13l-.36 2.2c-.05.34-.53.36-.62.03l-1.02-3.44a1 1 0 0 1 .01-1.26Z"/>
            </svg>
            Телеграм-канал X-Intellect
        </a>

        <p class="xi-maint__hint">
            <a href="{{ route('login') }}">Вход для администратора</a>
        </p>

        <p class="xi-maint__copy">© 2012-{{ date('Y') }} X-Intellect.org · архив проекта</p>
    </main>
</body>
</html>
