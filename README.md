# X-Intellect — восстановленный сайт проекта

Новый сайт проекта **X-Intellect** (ранее — «Сфера Разума», основан в 2012 г. А. Г. Глазом):
Laravel-приложение с админкой, публичной частью на архивных мотивах, аудиоплеером для
вики-страниц и SEO-автоматизацией. Построено по плану разработки
(`x-intellect-development-plan.md`) под ограничения виртуального хостинга
**hosting.timeweb.ru** (PHP 8.3, MySQL, cron, без постоянных фоновых процессов).

## Стек

- **Laravel 13** (PHP 8.3), Blade — серверный рендер, SEO из коробки
- **MySQL** `utf8mb4_unicode_ci` на проде, SQLite локально; поиск — MySQL FULLTEXT (LIKE-фолбэк на SQLite)
- **Alpine.js** — аудиоплеер, тултипы глоссария; **Trix** — редактор админки; **Vite + Tailwind** — сборка только локально/в CI
- **Database-очередь + cron** (`queue:work --stop-when-empty`) — фоновые задачи без воркер-процесса

## Что реализовано

| Этап плана | Реализация |
|---|---|
| 1. Модель данных | `sections`, `pages` (seo JSON, `source_type` — три эпохи контента, ревизии), `media`, `glossary_terms`, `redirects`, `menu_items` |
| 2. Admin CMS | `/admin` (auth + роли admin/editor, rate-limit логина, noindex), CRUD всего, Trix с кнопкой `[[audio:ID]]`, `php artisan import:archive` |
| 3. Публичная часть | космическая тема, бейджи эпох, хлебные крошки, вики-сайдбар, таймлайн истории, дисклеймер в футере |
| 4. Аудиоплеер | Alpine-компонент: плейлист, скорость, перемотка ±15/30с, позиция в localStorage; short-код `[[audio:ID]]` |
| 5. SEO | авто-slug (транслитерация), meta из seo-полей, JSON-LD (Article/Person/FAQPage/BreadcrumbList), `sitemap:generate`, middleware редиректов (301 архивные / 302 `/go/*` для обхода adblock) |
| 6. robots/llms | статичные `public/robots.txt`, `public/llms.txt` |
| 7. Контент | сидеры ядра: история 1982–2017, описание 2014 г., курсы с предупреждением, `/fesoterika`, глоссарий, редиректы `/go/*` с заглушки |

## Локальная разработка

```bash
composer install
cp .env.example .env && php artisan key:generate   # DB_CONNECTION=sqlite
touch database/database.sqlite
php artisan migrate --seed                          # админ: admin@x-intellect.org / SEED_ADMIN_PASSWORD
php artisan storage:link
npm install && npm run dev                          # или npm run build
php artisan serve
```

Тесты: `php artisan test` (41 тест: публичные страницы, редиректы, роли, SEO, ревизии).

## Импорт архива (Этап 0 → 7)

1. Локально выгрузить снапшоты Wayback Machine (CDX API по `x-intellect.org*`,
   `wiki.x-intellect.org*`, `forum.x-intellect.org*`, `sferarazuma.ru*`).
2. `php artisan import:archive storage/archive/wiki --section=wiki --source-type=archive_xintellect`
   — создаст **черновики** (MediaWiki-контейнеры распознаются автоматически).
3. Вычитка в админке → простановка эпохи/аудио → публикация. Sitemap пересоберётся сам.

Аудио из облачной папки загружается через админку («Медиа»), привязывается к странице,
в текст вставляется `[[audio:ID]]`. При нехватке диска на тарифе — поле «внешний URL»
(S3-совместимое хранилище), код не меняется.

## Деплой на hosting.timeweb.ru (Вариант А — вручную по SSH/Git)

1. `git clone git@github.com:Fesoterika/x-intellect.git` на хостинг (docroot домена → `public/`).
2. `composer install --no-dev --optimize-autoloader`
3. Локально `npm ci && npm run build`, залить `public/build/` по SFTP (в git её нет).
4. Один раз: скопировать `.env` (MySQL-доступы из панели, `APP_ENV=production`,
   `APP_DEBUG=false`, свой `APP_KEY`), создать БД **UTF-8** в панели.
5. `php artisan migrate --force && php artisan db:seed --force`
6. `php artisan storage:link && php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. Cron в панели Timeweb: `* * * * * php /путь/до/сайта/artisan schedule:run`
   (очередь + sitemap ежечасно — уже настроены в `routes/console.php`).

Обновления: `git pull origin main` → повторить шаги 2 (если менялись зависимости), 5–6.

**Вариант Б** (автодеплой): `.github/workflows/deploy.yml` — ручной запуск
(`workflow_dispatch`); перед включением на push задать секреты `SSH_HOST`,
`SSH_USERNAME`, `SSH_PRIVATE_KEY`, `DEPLOY_PATH` и проверить, что тариф разрешает
SSH-команды извне.

## Безопасность (Этап 8 плана)

- `.env` не в git; секреты — только на сервере и в GitHub Secrets; 2FA на GitHub-аккаунте
- Отдельный deploy-ключ (не личный), fine-grained token только на этот репозиторий
- Rate-limit на `/login` (Breeze), публичная регистрация отключена, сложные пароли admin/editor
- `composer audit` / `npm audit` перед релизом
- Права на запись только `storage/` и `bootstrap/cache/`
- `mysqldump` перед миграциями, затрагивающими данные (плюс штатные бэкапы Timeweb)

## Дальше

- Этап 0/7: массовая выгрузка и импорт архива (вики, библиотека, правила, приветствия-аудио)
- Позже: восстановление форума — чистый phpBB в `/forum/` на том же хостинге, отдельная БД,
  read-only архив тем из снапшотов
