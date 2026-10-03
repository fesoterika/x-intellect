# Карта кода X-Intellect

Актуализирована 04.10.2026 по исходникам, `git ls-files`, `artisan route:list --json`
и `phpunit --list-tests`. Исходная версия — 13.08.2026.
Если карта расходится с кодом, верен код. Граф codebase-memory не служит источником
текущих чисел: [ограничения прежнего индекса](#чему-в-графе-нельзя-верить).

Карта предназначена для разработки: хранится в Git, локальной и облачной копиях.
В `.github/workflows/deploy.yml` исключена из rsync на продакшен. При ручном `git pull`
на сервере Git всё равно получает отслеживаемый файл; приложение карту не использует,
в `public/` её нет. Для отдельной загрузки через rsync сохраняйте исключение
`--exclude '/docs/project-map.md'`.

## Содержание

- [Структура и точки входа](#структура-и-точки-входа)
- [Данные](#данные) · [Маршрутизация](#маршрутизация)
- [Контроллеры](#контроллеры) · [Сервисы и рендер](#сервисы-и-рендер)
- [Обвязка и фоновые задачи](#обвязка-и-фоновые-задачи)
- [Консольные команды](#консольные-команды) · [Тесты](#тесты)
- [Последние изменения](#последние-изменения) · [Грабли](#грабли)
- [Чему в графе нельзя верить](#чему-в-графе-нельзя-верить)

## Структура и точки входа

Laravel 13 / PHP 8.3+, Blade, MySQL (совместимость с 5.7), Alpine.js, Trix,
Vite 8 и Tailwind CSS 3. Проект сохраняет архив «Икс-Интеллект» / «Сфера Разума»:
статьи, вики, стенограммы, аудио, глоссарий и форум phpBB в режиме чтения.

В Git: **201 PHP-файл без Blade**, **68 Blade-шаблонов** и **4 JS-модуля
в `resources/js/`**. Зависимости `vendor/`, `node_modules/`, кеши и сборка не учитываются.

| Путь | Назначение |
|---|---|
| `public/index.php` → `bootstrap/app.php` | HTTP-вход, маршруты, middleware, обработка исключений |
| `artisan` → `routes/console.php` | Консоль и расписание фоновых задач |
| `app/` | Модели, контроллеры, сервисы, команды, обсерверы и задания |
| `database/migrations/`, `database/seeders/` | Схема и начальное наполнение / локальный администратор |
| `database/x_intellect.sql` | Очищенный дамп контента; источник актуального контента — прод |
| `resources/views/layouts/site.blade.php`, `resources/views/site/` | Публичная оболочка и страницы |
| `resources/views/admin/`, `resources/js/admin.js` | Админка и расширения Trix |
| `resources/js/app.js` | Alpine-аудиоплеер, тултипы, счётчики, поведение публичных страниц |
| `resources/js/compat.js`, `resources/js/starfield.js` | Совместимость браузеров / storage и canvas-фон |
| `resources/css/`, `resources/content/opgry.html` | Стили, включая `grammar.css`, и восстановленный справочник |
| `vite.config.js` → `public/build/` | Сборка CSS/JS; сборка не хранится в Git |
| `storage/app/public/media/` → `public/storage` | Медиа и публичный симлинк (`artisan storage:link`) |
| `dev-server.php` | Локальный роутер `php -S` с HTTP Range для аудио |
| `.github/workflows/deploy.yml` | Ручной GitHub Actions-деплой на Timeweb |
| `docs/`, `tests/` | Рабочая документация и проверки |

## Данные

**11 моделей** в `app/Models/`: `Page`, `PageRevision`, `Section`, `Media`, `MenuItem`,
`GlossaryTerm`, `ForumTopic`, `ForumPost`, `Redirect`, `Setting`, `User`.
**22 миграции** в `database/migrations/`.

- `sections.parent_id` — корневые разделы и подразделы. `Page::url()` строит адрес
  через `rootAncestor()`: перенос внутри одного корня не меняет URL страницы.
- `pages.body` — исходник; `body_rendered` — кеш преобразований при сохранении.
  `status`, `is_listed`, `in_wiki_menu`, `is_pinned` управляют публикацией и навигацией;
  опубликованные unlisted-страницы доступны напрямую и участвуют в поиске.
- `pages.seo` — JSON с SEO-полями; `source_type`, `source_url`, `archived_at`
  описывают происхождение. `published_at` — дата материала для сортировки.
- `page_revisions` — прежние заголовок, тело, происхождение и причина правки.
- `media` — привязка к странице, тип, путь, порядок и длительность; одна физическая
  запись аудио может быть связана с несколькими строками БД.
- `pages.disclaimer`, `forum_topics.disclaimer` — примечания под материалом.
- `forum_posts.old_id` используется в якоре `#p{old_id}`, а не новый `id`.
- `redirects` — старый путь, цель, HTTP-код и счётчик переходов; `settings` —
  настройки приложения, включая режим техработ; `menu_items` — меню шапки и подвала.

Срез локальной БД после синхронизации с продом 03.10.2026: 340 страниц
(**339 опубликованы**), 23 видимых раздела, 183 аудио при опубликованных страницах,
87 терминов, 105 тем / 1487 сообщений форума, 1101 редирект (1098 с кодом 301).
Это срез данных, а не константы приложения; актуальные счётчики собирает `HomeController`.

## Маршрутизация

**73 маршрута** в текущем локальном окружении. Источник — `artisan route:list --json`,
а не число вызовов `Route::`: resource разворачивается в несколько маршрутов.

| Группа | Количество |
|---|---:|
| Публичные контроллеры `Site` | 9 |
| Админка `/admin/*` | 45 |
| Аутентификация (`routes/auth.php`) | 13 |
| Профиль `/profile` (GET/PATCH/DELETE) | 3 |
| Laravel: `/up`, GET/PUT `/storage/{path}` | 3 |

Последняя группа зависит от конфигурации окружения. Публичные маршруты:

```text
GET /                            HomeController
GET /search                      SearchController (throttle:30,1)
GET /glossary                    GlossaryController
GET /fesoterika                  PageController@fesoterika
GET /forum                       ForumController@index
GET /forum/search                ForumController@search (throttle:30,1)
GET /forum/{topic:slug}           ForumController@show
GET /{section:slug}              SectionController@show
GET /{section:slug}/{pageSlug}    PageController@show
```

Порядок в `routes/web.php` критичен: фиксированные пути идут до замыкающих путей
разделов; `/forum/search` — до темы. В админке `media/orphans`, операции над группами
и разделами форума объявлены до динамических параметров. Публичная регистрация
отключена в `routes/auth.php`. Для тем/разделов — binding по `slug`;
страницу по `pageSlug` ищет контроллер в пределах корневого раздела.

## Контроллеры

- **6 публичных**, `app/Http/Controllers/Site/`: `Home`, `Page`, `Section`, `Search`,
  `Glossary`, `Forum` (все имена с суффиксом `Controller`).
- **11 административных**, `app/Http/Controllers/Admin/`: `Dashboard`, `Page`,
  `PageRevision`, `Section`, `Media`, `MenuItem`, `GlossaryTerm`, `Forum`, `Redirect`,
  `Maintenance`, `EditorUpload`.
- `Auth/` и `ProfileController` — вход, пароль, подтверждение почты и профиль.
  Проверки форм — `app/Http/Requests/`.

Админка требует `auth` + `role:admin,editor`; операции с редиректами, меню и форумом
дополнительно требуют `can:admin`. Поиск на MySQL сочетает LIKE и FULLTEXT;
на SQLite используется `RussianText` / `xi_lower()`.

## Сервисы и рендер

**25 классов** в `app/Services/`.

При сохранении `PageObserver::saving()` сначала нормализует исходник:

```text
TrixTables.extract → TrixEmbeds.extract → LocalLinks.relativize
→ ImageSeo.process → TimelineTagger.process → SeoService (slug и SEO)
```

При изменении `body` или отсутствии `body_rendered` собирает кеш:

```text
LinkTargets → ImageAligner → ImageFigures → AttachmentDownloads
→ TableImagePairer → ImageGallery → GlossaryLinker
```

`ImageGallery` должен идти после `TableImagePairer`: обёртка галереи иначе разорвёт
соседство картинки и таблицы. На HTTP-выдаче `PageRenderer` добавляет lazy loading
изображениям и разворачивает `[[audio:ID]]` в `site.partials.audio-player`.

| Группа | Классы |
|---|---|
| Импорт и восстановление (7) | `ArchiveHtmlCleaner`, `ArchiveLinkRestorer`, `MediaWikiArchive`, `PhpbbParser`, `WordPressArchive`, `SferaRazumaArchive`, `OfflineSnapshotIndex` |
| Подготовка и рендер (14) | `TrixTables`, `TrixEmbeds`, `LocalLinks`, `ImageSeo`, `TimelineTagger`, `SeoService`, `LinkTargets`, `ImageAligner`, `ImageFigures`, `AttachmentDownloads`, `TableImagePairer`, `ImageGallery`, `GlossaryLinker`, `PageRenderer` |
| Прикладные (4) | `AudioLibrary`, `OrphanMedia`, `ExcerptMaker`, `IndexNow` |

## Обвязка и фоновые задачи

**4 middleware** в `app/Http/Middleware/`; порядок задаёт `bootstrap/app.php`:

1. `SecurityHeaders` — внешний слой; последний `prepend` ставит его первым,
   поэтому заголовки попадают и на ответы редиректов.
2. `HandleRedirects` — до маршрутизации; 301 архивные, 302 `/go/*`, кеш ответа на час.
   Учитывает query-string и WordPress `/?p=ID`, `/index.php?p=ID`, `?page_id=ID`
   с дополнительными параметрами. HTTP-методы — только GET/HEAD.
3. `MaintenanceMode` — в web-группе после сессии, до `SubstituteBindings`
   (`remove` + `append`). Неизвестные маршруты закрывает также обработчик 404.
4. `EnsureUserHasRole` — alias `role`, проверка ролей.

**2 обсервера**: `PageObserver`, `MediaObserver`. Их регистрирует `AppServiceProvider`,
который также задаёт Gate `admin` и общий composer меню `site.*`.
`PageObserver` сохраняет ревизию при смене title/body, поддерживает старые адреса 301,
схлопывает входящие редиректы при переезде и сбрасывает прежний автоканоникал.

**2 задания** в `app/Jobs/`:

- `RegenerateSitemap` — пересборка sitemap после сохранения опубликованного материала,
  смены статуса или удаления.
- `SubmitToIndexNow` — отправка изменившихся/старых/удалённых URL при заданном ключе.
  Настройки — `config/indexnow.php`; без `INDEXNOW_KEY` отправка отключена.

`routes/console.php`: каждую минуту `queue:work --stop-when-empty --max-time=50`
с `withoutOverlapping()`, раз в час — `sitemap:generate`.
`GenerateSitemap` пишет `sitemap.xml`, при наличии медиа — `sitemap-media.xml`
и `sitemap-index.xml`, атомарно заменяя файлы; `lastmod` берётся из дат контента.

`app/Support/RussianText.php` обеспечивает кириллическую сортировку; на SQLite
регистрирует коллацию `xi_ru` и функцию `xi_lower()`, на MySQL использует его коллацию.

## Консольные команды

**28 собственных команд** в `app/Console/Commands/`. Ниже имена для Artisan;
параметры и флаги уточняйте через `php artisan help <команда>`.

| Команда | Класс | Назначение |
|---|---|---|
| `import:offline-explorer` | `ImportOfflineExplorer` | Основной сайт из офлайн-слепка |
| `import:offline-wiki` | `ImportOfflineWiki` | Вики и глоссарий из слепка |
| `import:offline-audio` | `ImportOfflineAudio` | Привязка архивных mp3 |
| `import:offline-forum` | `ImportOfflineForum` | phpBB из офлайна / Wayback |
| `import:wayback-wiki` | `ImportWaybackWiki` | Недостающие вики-страницы |
| `import:wayback-posts` | `ImportWaybackPosts` | Записи основного сайта |
| `import:sferarazuma` | `ImportSferaRazuma` | Аудио и метаданные до 2012 |
| `import:archive` | `ImportArchive` | HTML-снимки как черновики |
| `remap:archive-links` | `RemapArchiveLinks` | Внутренняя перелинковка |
| `links:restore` | `RestoreArchiveLinks` | Возврат потерянных ссылок |
| `sessions:restore-missing` | `RestoreMissingSessions` | Страницы сеансов из аудио |
| `content:sync-dates` | `SyncArchiveDates` | Даты публикаций из слепка |
| `site:menu-subsections` | `SyncMenuSubsections` | Подразделы в меню |
| `navigation:merge-sessions` | `MergeSessionNavigation` | Объединение навигации сеансов |
| `content:backfill` | `BackfillContentMeta` | Даты и анонсы |
| `site:content-fixes-2026` | `ContentFixes2026` | Точечные исправления контента |
| `site:structure-2026` | `ApplySiteStructure2026` | Разделы и раскладка страниц |
| `site:restore-grammar-layout` | `RestoreGrammarLayout` | `/rules/opgry` из `resources/content/opgry.html` |
| `audio:upgrade` | `UpgradeArchiveAudio` | Более полные копии аудио |
| `media:durations` | `FillMediaDurations` | Длительности аудио |
| `sessions:enrich-sfera` | `EnrichSessionsFromSfera` | Стенограммы «Сферы Разума» |
| `db:copy-from-sqlite` | `CopyFromSqlite` | Перенос SQLite → текущая БД |
| `audit:archive` | `AuditArchive` | Полнота архива |
| `redirects:check` | `CheckRedirects` | Цели, цепочки, циклы, черновики, перекрытие живых URL |
| `seo:canonical` | `CheckCanonicals` | Canonical относительно фактических адресов |
| `sitemap:generate` | `GenerateSitemap` | XML-карты страниц и медиа |
| `indexnow:key` | `IndexNowKey` | Файл подтверждения ключа |
| `indexnow:submit` | `IndexNowSubmit` | Отправка URL, включая `--all` |

`redirects:check` также признаёт `/sitemap.xml` действующей целью, если файл существует.
`site:restore-grammar-layout` проверяет URL и хеш исходного тела, сохраняет ревизию,
при повторном запуске не меняет уже восстановленную страницу и не затирает новые правки.

## Тесты

**37 Feature-файлов** (включая `tests/Feature/Auth/`) + **5 Unit-файлов**;
`phpunit --list-tests` перечисляет **335 тестов**.

```sh
php artisan test
php vendor/bin/phpunit --list-tests
php artisan route:list --json
```

Тесты используют отдельную MySQL-базу `x_intellect_test` из `phpunit.xml`;
нужен локальный MySQL. Покрыты публичные страницы, поиск, SEO, админка/роли,
редиректы и переезд страниц, IndexNow, аудио, галереи, импорт и восстановление ссылок.
Проверки браузерной вёрстки справочника описаны в [отчёте](opgry-layout-2026-10-03.md).

## Последние изменения

- Август: IndexNow и Open Graph для всех типов публичных страниц, `Clean-param`;
  сентябрь: новые брендовые изображения, исправления поиска и тёмной темы.
- 03.10: восстановление WordPress shortlinks и целей старых редиректов —
  [отчёт](legacy-redirects-2026-10-03.md), [реестр](legacy-redirects-2026-10-03.json).
- 03.10: 21 архивный адрес консультаций направлен на `/about/contacts` —
  [отчёт](consultation-redirects-2026-10-03.md), [реестр](consultation-redirects-2026-10-03.json).
- 03.10: адаптация `pre` к мобильным экранам; восстановление `/rules/opgry`
  (85 пунктов оглавления, 78 таблиц) — [отчёт](opgry-layout-2026-10-03.md).

## Грабли

**Кеш тела.** `DB::table()` обходит `PageObserver`: изменение только `body` оставит
старый `body_rendered`. Обычно сохраняйте через модель; для массовых правок явно
обновляйте оба представления. Ревизии хранят удалённый текст и входят в публичный
дамп: при удалении контента проверяйте историю тоже.

**Переезд страницы.** Логика старых URL и canonical живёт в `PageObserver`.
Перенос через прямой SQL обходит её. Контент редактируется на проде; дамп и локальная
БД обновляются в направлении прод → локальная копия, а не наоборот.

**Сессии.** `SESSION_DRIVER=file` выбран намеренно: database-драйвер сохранял IP
и user-agent посетителей в `sessions`. В публичном дампе users, sessions, токены,
кеш и очереди должны оставаться без данных.

**Сборка и медиа.** `public/build/`, `vendor/`, `node_modules/`, аудио и архив курсов
не входят в Git. Код синхронизируется Git, сборка — отдельным rsync, медиа — без
`--delete`. Не перезаписывайте `.env` и рабочую БД при синхронизации копий.

**Старые браузеры.** JS-target в Vite — `es2017`, Safari/iOS 11; CSS-target —
Safari/iOS 9. Понижение async ломает Alpine. Старые браузеры получают нативный
аудиоплеер и раскрытое меню из fallback в публичном layout.

**Хостинг.** По проверкам августа 2026, статику отдаёт nginx мимо Apache:
`mod_headers`, `mod_deflate`, `AddType` из `.htaccess` для неё не гарантированы.
Редирект конечного слеша задан абсолютным HTTPS-адресом, чтобы не получить второй
переход через HTTP за прокси. Точечные файлы закрыты `FilesMatch` и rewrite;
`.well-known` исключён явным условием. Обработку `/.well-known/` перехватывал nginx;
эти наблюдения не заменяют проверку текущей конфигурации хостинга.

**PHP и workflow.** На Timeweb для Artisan используйте `/opt/php8.3/bin/php`:
системный PHP при прежних проверках был 8.2. Workflow запускается вручную, его
post-deploy пока вызывает обычный `php`; перед использованием исправьте путь
под сервер. Деплой документации не требует миграций и пересборки ассетов.

---

## Чему в графе нельзя верить

Исторические наблюдения при индексации в августе 2026. Индексатор заново не запускался;
это ограничения того снимка графа, а не результаты новой проверки. Текущие числа
выше получены из исходников и Artisan.

**Маршруты.** Узлы `Route` собраны из строк в тестах (`$this->get('/articles?sort=new')`),
а не из `routes/web.php`. Один узел вытащен даже из HTML-строки внутри теста. У них нет ни
файла, ни обработчика. Реальные маршруты — только через `artisan route:list`.

**Метрики связности на универсальных именах.** Граф показывал 164 входящих вызова
у `SectionController::create`; в списке оказались `PhpbbParser::postDate`, который на самом
деле вызывает `Carbon::create()`. Резолвер сводит все `create()`, `get()`, `count()`,
`update()`, `exists()` к одному узлу. Числа завышены.

**Точки входа.** Указываются 13 JS-функций. Настоящая точка входа `public/index.php`
проиндексирована, но не распознаётся: эвристика ищет функции без входящих вызовов.

**Слои.** Появляются фантомы `html`, `php` и даже
`php?title=%D0%93%D0%BB%D0%BE%D1%81%D1%81%D0%B0%D1%80%D0%B8%D0%B9` — индексатор нарезал
URL старой вики по точке и принял куски за пакеты.

**Тихие пропуски.** `public/.htaccess`, `public/robots.txt`, `public/llms.txt` на диске
есть, но в графе их нет — и в отчёте о покрытии они **не значатся**. Отсутствие файла
в списке пробелов не доказывает, что он проиндексирован.

**Режим индексации.** В `moderate` из индекса выпадают `public/`, `docs/`
и `database/migrations/`. Для полной картины нужен `full`.
