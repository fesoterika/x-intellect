# Карта проекта X-Intellect

Составлена 13.08.2026. Источник данных — файловая система, `artisan route:list` и граф
codebase-memory (индекс на 2217 узлов / 8864 связи, режим `full`).

> **Как читать.** Все числа ниже проверены по исходникам, а не взяты из графа: у индексатора
> есть систематические ошибки, они описаны в конце документа. Если карта разойдётся
> с кодом — верен код.

---

## Что это

Laravel-приложение (PHP 8.3, Blade, MySQL), восстанавливающее архив проекта
«Икс-Интеллект» / «Сфера Разума»: статьи, вики, стенограммы сеансов, аудиозаписи,
глоссарий и архив форума phpBB в режиме чтения.

303 PHP-файла, 67 blade-шаблонов, 7 JS-модулей.

---

## Слой данных

**11 моделей** (`app/Models`): `Page`, `PageRevision`, `Section`, `Media`, `MenuItem`,
`GlossaryTerm`, `ForumTopic`, `ForumPost`, `Redirect`, `Setting`, `User`.

**22 миграции** (`database/migrations`). Ключевые особенности схемы:

- `sections.parent_id` — иерархия глубиной 1. URL страницы **не зависит** от подраздела:
  `Page::url()` строит адрес через `rootAncestor()`.
- `pages.body` и `pages.body_rendered` — исходник и **кеш рендера**. Правка `body` в обход
  модели не обновляет `body_rendered`, и сайт продолжит отдавать старое (см. «Грабли»).
- `pages.disclaimer`, `forum_topics.disclaimer` — служебные примечания под материалом.
- `forum_posts.old_id` — якорь `#p{old_id}` в адресах, не `id`.

---

## Маршрутизация

**73 маршрута.** Определены в `routes/web.php` (37 вызовов `Route::`) и `routes/auth.php` (15).

| Группа | Кол-во |
|---|---|
| Публичная часть | 13 |
| Админка (`/admin/*`) | 45 |
| Аутентификация (Breeze) | 15 |

Публичные маршруты:

```
GET  /                            HomeController
GET  /search                      SearchController
GET  /glossary                    GlossaryController
GET  /forum                       ForumController@index
GET  /forum/search                ForumController@search
GET  /forum/{topic:slug}          ForumController@show
GET  /fesoterika                  PageController@fesoterika
GET  /{section:slug}              SectionController@show
GET  /{section:slug}/{pageSlug}   PageController@show
```

Два последних — замыкающие, поэтому **порядок объявления критичен**: `/forum/search`
обязан идти до `/forum/{topic}`, иначе поиск будет принят за slug темы.

Модели биндятся по `slug`, а не по `id`.

---

## Контроллеры

**Публичные (6)** — `app/Http/Controllers/Site`: `Home`, `Page`, `Section`, `Search`,
`Glossary`, `Forum`.

**Админка (11)** — `app/Http/Controllers/Admin`: `Dashboard`, `Page`, `PageRevision`,
`Section`, `Media`, `MenuItem`, `GlossaryTerm`, `Forum`, `Redirect`, `Maintenance`,
`EditorUpload`.

---

## Сервисы — фактическое ядро системы

24 класса в `app/Services`, три группы.

**Конвейер рендера страницы.** Вызывается из `PageObserver` при сохранении, результат
ложится в `body_rendered`. Порядок вложенности значим:

```
linkTargets → imageAligner → imageFigures → downloads → pairer → gallery → glossary
```

`ImageGallery` обязан идти **после** `TableImagePairer`: обёртка галереи разорвала бы
соседство фигуры с таблицей и сломала пары «картинка + таблица».

**Импорт архива.** `ArchiveHtmlCleaner`, `ArchiveLinkRestorer`, `MediaWikiArchive`,
`PhpbbParser`, `WordPressArchive`, `SferaRazumaArchive`, `OfflineSnapshotIndex`.

**Редактор и прикладное.** `TrixTables`, `TrixEmbeds` (вложения Trix), `LocalLinks`
(чистка localhost-ссылок), `AudioLibrary`, `OrphanMedia`, `ExcerptMaker`, `SeoService`,
`ImageSeo`, `TimelineTagger`.

---

## Обвязка

**Middleware (4).** Порядок в `bootstrap/app.php` продуман и хрупок:

- `SecurityHeaders` — `prepend` последним, поэтому оборачивает весь стек;
- `HandleRedirects` — 301 с архивных адресов, 302 для `/go/*`;
- `MaintenanceMode` — в web-группе после `StartSession`, но **до** `SubstituteBindings`
  (сделано через `remove` + `append`: removals применяются раньше appends);
- `EnsureUserHasRole` — роли `admin` / `editor`.

**Обсерверы (2).** `PageObserver` — конвейер рендера, slug, SEO-поля, ревизии (реагирует
только на `title`/`body`). `MediaObserver`.

**Прочее.** `Jobs/RegenerateSitemap`, `Support/RussianText` — кириллическая сортировка
(на SQLite регистрирует коллацию `xi_ru` и функцию `xi_lower()`; MySQL сортирует сам).

---

## Консольные команды (25)

Импортёры: `ImportOfflineExplorer`, `ImportOfflineWiki`, `ImportOfflineAudio`,
`ImportOfflineForum`, `ImportWaybackWiki`, `ImportWaybackPosts`, `ImportSferaRazuma`,
`ImportArchive`.

Обслуживание: `RemapArchiveLinks`, `RestoreArchiveLinks`, `RestoreMissingSessions`,
`SyncArchiveDates`, `SyncMenuSubsections`, `MergeSessionNavigation`, `BackfillContentMeta`,
`ContentFixes2026`, `ApplySiteStructure2026`, `UpgradeArchiveAudio`, `FillMediaDurations`,
`EnrichSessionsFromSfera`, `CopyFromSqlite`.

Проверки: `AuditArchive`, `CheckRedirects`, `CheckCanonicals`, `GenerateSitemap`.

---

## Тесты

30 Feature + 5 Unit, 437 рёбер `TESTS` в графе. Прогон — `php artisan test`.

---

## Грабли

**`body_rendered`.** Правка `pages.body` через `DB::table` (в обход модели и обсервера)
не пересобирает кеш рендера — сайт отдаёт старое. Надо либо сохранять через модель, либо
править обе колонки.

**`page_revisions` хранит удалённое.** Снимки истории правок сохраняют текст, уже убранный
со страницы, и попадают в публичный дамп `database/x_intellect.sql`. При удалении чего-либо
со страницы проверяйте и ревизии.

**Сессии — файловый драйвер.** `SESSION_DRIVER=file` намеренно: драйвер `database` писал
IP и user-agent каждого посетителя в таблицу `sessions`. Не возвращать.

**Слеш на конце адреса.** В `public/.htaccess` цель перенаправления задана абсолютным
https-адресом намеренно — Apache за прокси хостинга собирал из относительной цели ссылку
на `http`, выходило два перехода со сменой протокола.

**Статику отдаёт nginx мимо Apache.** Блоки `mod_headers`, `mod_deflate` и `AddType`
в `.htaccess` на этом хостинге не применяются. Файл корректен и пригодится при переезде,
но рассчитывать на эти заголовки нельзя.

**PHP на проде.** Консольный `php` — 8.2 и для проекта не годится (`^8.3`): все команды
через `/opt/php8.3/bin/php`.

---

## Чему в графе codebase-memory нельзя верить

Проверено на этом проекте.

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
