<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function seedCore(): void
    {
        $this->seed();
    }

    public function test_home_page_renders(): void
    {
        $this->seedCore();

        $this->get('/')
            ->assertOk()
            ->assertSee('X-Intellect', false)
            ->assertSee('Разделы архива');
    }

    public function test_section_page_renders(): void
    {
        $this->seedCore();

        $this->get('/history')
            ->assertOk()
            ->assertSee('История проекта');
    }

    public function test_published_page_renders_with_source_badge_and_json_ld(): void
    {
        $this->seedCore();

        $this->get('/about/o-sajte-x-intellect')
            ->assertOk()
            ->assertSee('О сайте X-INTELLECT')
            ->assertSee('Архив X-Intellect')          // бейдж эпохи
            ->assertSee('application/ld+json', false) // JSON-LD разметка
            ->assertSee('Владелец сайта не является автором проекта'); // дисклеймер в футере
    }

    public function test_draft_page_is_hidden_from_guests(): void
    {
        $this->seedCore();

        $this->get('/rules/pravila-x-intellect')->assertNotFound();
    }

    public function test_fesoterika_author_page_renders_with_person_schema(): void
    {
        $this->seedCore();

        $this->get('/fesoterika')
            ->assertOk()
            ->assertSee('fesoterika')
            ->assertSee('"@type": "Person"', false)
            ->assertSee('github.com/Fesoterika', false);
    }

    public function test_courses_page_shows_responsibility_warning(): void
    {
        $this->seedCore();

        $this->get('/courses/arhivy-kursov-aleksandra-glaza')
            ->assertOk()
            ->assertSee('во вред другим или не по назначению влечёт за собой ответственность', false);
    }

    public function test_go_wrapper_redirects_to_external_url(): void
    {
        $this->seedCore();

        $this->get('/go/dzen.html')
            ->assertRedirect('https://dzen.ru/fesoterika')
            ->assertStatus(302);

        $this->assertSame(1, Redirect::where('from_path', '/go/dzen.html')->value('hits'));
    }

    public function test_old_archive_url_redirects_permanently(): void
    {
        $this->seedCore();

        $this->get('/wiki/index.php')->assertStatus(301)->assertRedirect('/wiki');
    }

    public function test_old_mediawiki_glossary_url_redirects_to_glossary(): void
    {
        $this->seedCore();

        // Архивный адрес MediaWiki с query-string (percent-encoded кириллица)
        $this->get('/wiki/index.php?title=%D0%93%D0%BB%D0%BE%D1%81%D1%81%D0%B0%D1%80%D0%B8%D0%B9')
            ->assertStatus(301)
            ->assertRedirect('/glossary');

        // Прежний slug нового сайта тоже ведёт на новый адрес
        $this->get('/glossarij')->assertStatus(301)->assertRedirect('/glossary');
    }

    public function test_glossary_page_renders_faq_schema(): void
    {
        $this->seedCore();

        $this->get('/glossary')
            ->assertOk()
            ->assertSee('Биоэкран')
            ->assertSee('FAQPage', false);
    }

    public function test_glossary_term_has_own_indexable_url(): void
    {
        $this->seedCore();

        $term = \App\Models\GlossaryTerm::where('term', 'Биоэкран')->firstOrFail();
        $base = rtrim(config('app.url'), '/');

        $this->get('/glossary?term='.$term->slug)
            ->assertOk()
            ->assertSee('Биоэкран - глоссарий проекта X-Intellect')
            // canonical ведёт на сам термин, а не на общий список
            ->assertSee('<link rel="canonical" href="'.$base.'/glossary?term='.$term->slug.'">', false)
            ->assertSee('DefinedTerm', false)
            ->assertDontSee('noindex', false);
    }

    public function test_glossary_free_text_search_url_is_not_indexable(): void
    {
        $this->seedCore();

        $base = rtrim(config('app.url'), '/');

        // ?q= — служебное состояние поиска: noindex + canonical на /glossary
        $this->get('/glossary?q=биоэк')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<link rel="canonical" href="'.$base.'/glossary">', false);

        // сам список остаётся индексируемым
        $this->get('/glossary')->assertOk()->assertDontSee('noindex', false);
    }

    public function test_glossary_unknown_term_redirects_to_list(): void
    {
        $this->seedCore();

        $this->get('/glossary?term=takogo-termina-net')->assertRedirect('/glossary');
    }

    public function test_old_wiki_term_url_redirects_to_term_query(): void
    {
        $this->seedCore();

        $term = \App\Models\GlossaryTerm::where('term', 'Биоэкран')->firstOrFail();

        \App\Models\Redirect::create([
            'from_path' => '/wiki/index.php?title=Биоэкран',
            'to_url' => $term->url(),
            'status_code' => 301,
        ]);

        // Старый вики-адрес ведёт на адрес термина, а не на якорь #slug
        $this->get('/wiki/index.php?title=%D0%91%D0%B8%D0%BE%D1%8D%D0%BA%D1%80%D0%B0%D0%BD')
            ->assertStatus(301)
            ->assertRedirect('/glossary?term='.$term->slug);
    }

    public function test_glossary_terms_are_listed_in_sitemap(): void
    {
        $this->seedCore();

        $this->artisan('sitemap:generate')->assertSuccessful();

        $xml = file_get_contents(public_path('sitemap.xml'));
        $base = rtrim(config('app.url'), '/');
        $term = \App\Models\GlossaryTerm::where('term', 'Биоэкран')->firstOrFail();

        $this->assertStringContainsString($base.'/glossary</loc>', $xml);
        // в XML амперсанда нет — только один параметр, но loc экранируется e()
        $this->assertStringContainsString($base.'/glossary?term='.$term->slug.'</loc>', $xml);
    }

    public function test_articles_section_replaced_mag_everywhere(): void
    {
        $this->seedCore();

        $this->get('/articles')->assertOk();

        // Старый слаг раздела не должен воскресать из сидеров
        $this->assertNull(\App\Models\Section::where('slug', 'mag')->first());
        $this->assertNotNull(\App\Models\MenuItem::where('location', 'header')->where('url', '/articles')->first());
        $this->assertNull(\App\Models\MenuItem::where('url', '/mag')->first());

        // Ни один редирект не ведёт на мёртвый префикс /mag/
        $this->assertSame(0, \App\Models\Redirect::where('to_url', 'like', '/mag/%')->count());
    }

    public function test_header_menu_contains_glossary_as_wiki_submenu(): void
    {
        $this->seedCore();

        $wiki = \App\Models\MenuItem::where('location', 'header')->where('url', '/wiki')->first();
        $glossary = \App\Models\MenuItem::where('location', 'header')->where('url', '/glossary')->first();

        $this->assertNotNull($glossary);
        $this->assertSame($wiki->id, $glossary->parent_id);

        // На странице подменю рендерится внутри пункта «Вики»
        $this->get('/')
            ->assertOk()
            ->assertSee('nav-submenu', false)
            ->assertSee('Глоссарий');
    }

    public function test_glossary_terms_are_linked_in_page_body(): void
    {
        $this->seedCore();

        $page = Page::where('slug', 'o-sajte-x-intellect')->first();

        $this->assertStringContainsString('glossary-term', $page->body_rendered);
    }

    public function test_search_finds_published_pages(): void
    {
        $this->seedCore();

        // По запросу «Глаз» есть и страницы, и термины глоссария —
        // заголовок списка страниц меняется на «Страницы»
        $this->get('/search?'.http_build_query(['q' => 'Глаз']))
            ->assertOk()
            ->assertSee('Страницы');
    }

    public function test_search_ranks_title_matches_before_body_matches(): void
    {
        $this->seedCore();
        $section = Section::where('slug', 'articles')->firstOrFail();

        // Совпадение только в теле — новее, но должно идти после совпадения в заголовке
        Page::create([
            'section_id' => $section->id, 'title' => 'Прочая страница', 'slug' => 'telo-match',
            'body' => '<p>В тексте упоминается эталонизация процесса.</p>',
            'status' => 'published', 'published_at' => '2015-01-01',
        ]);
        Page::create([
            'section_id' => $section->id, 'title' => 'Эталонизация человека', 'slug' => 'title-match',
            'body' => '<p>Тело без ключевого слова в начале.</p>',
            'status' => 'published', 'published_at' => '2013-01-01',
        ]);

        $this->get('/search?'.http_build_query(['q' => 'эталонизация']))
            ->assertOk()
            ->assertSeeInOrder(['Эталонизация человека', 'Прочая страница']);
    }

    public function test_search_is_case_insensitive(): void
    {
        $this->seedCore();

        // LIKE в SQLite не сворачивает регистр кириллицы — контроллер
        // сравнивает через зарегистрированную функцию xi_lower()
        $this->get('/search?'.http_build_query(['q' => 'хроносфера']))
            ->assertOk()
            ->assertSee('Хроносфера');
    }

    public function test_search_shows_matching_glossary_terms_with_links(): void
    {
        $this->seedCore();

        // Термин находится независимо от регистра, карточка ведёт на его адрес
        $this->get('/search?'.http_build_query(['q' => 'биоэкран']))
            ->assertOk()
            ->assertSee('В глоссарии')
            ->assertSee('/glossary?term=bioekran', false);
    }

    public function test_search_results_are_paginated(): void
    {
        $this->seedCore();

        $section = Section::where('slug', 'wiki')->first();
        for ($i = 1; $i <= 25; $i++) {
            Page::create([
                'section_id' => $section->id,
                'title' => "Хроносфера запись {$i}",
                'body' => '<p>Материал о хроносфере №'.$i.'</p>',
                'status' => 'published',
            ]);
        }

        // Первая страница: не более 20 результатов + ссылка на 2-ю
        $first = $this->get('/search?'.http_build_query(['q' => 'Хроносфера запись']));
        $first->assertOk()
            ->assertSee('Найдено: 25')
            ->assertSee('page=2', false);

        $this->assertLessThanOrEqual(20, substr_count($first->getContent(), '<a class="page-card'));

        // Вторая страница отдаёт остаток и сохраняет строку поиска
        $this->get('/search?'.http_build_query(['q' => 'Хроносфера запись', 'page' => 2]))
            ->assertOk()
            ->assertSee('Хроносфера запись');
    }

    public function test_short_query_shows_hint(): void
    {
        $this->seedCore();

        $this->get('/search?'.http_build_query(['q' => 'а']))
            ->assertOk()
            ->assertSee('не менее двух символов');
    }

    public function test_section_shows_pagination_and_sort_control(): void
    {
        $this->seedCore();

        $section = Section::where('slug', 'articles')->firstOrFail();
        for ($i = 1; $i <= 25; $i++) {
            Page::create([
                'section_id' => $section->id,
                'title' => "Статья номер {$i}",
                'body' => '<p>Тело статьи '.$i.'</p>',
                'status' => 'published',
            ]);
        }

        $this->get('/articles')
            ->assertOk()
            ->assertSee('xi-pagination', false) // страницы пагинации внизу
            ->assertSee('page=2', false)
            ->assertSee('Сортировка')
            ->assertSee('по алфавиту: А → Я');
    }

    public function test_section_sorting_orders_titles_and_dates(): void
    {
        $this->seedCore();

        $section = Section::where('slug', 'articles')->firstOrFail();
        foreach ([
            ['Яблоко', '2013-05-01'],
            ['арбуз', '2012-01-01'],   // нижний регистр — сортировка регистронезависима
            ['Берёза', '2014-09-09'],
        ] as [$title, $date]) {
            Page::create([
                'section_id' => $section->id,
                'title' => $title,
                'body' => '<p>Тело…</p>',
                'status' => 'published',
                'published_at' => $date,
            ]);
        }

        // По умолчанию — по дате, сначала новые (SectionController::DEFAULT_SORT)
        $this->get('/articles')->assertOk()->assertSeeInOrder(['Берёза', 'Яблоко', 'арбуз']);
        $this->get('/articles?sort=abc')->assertOk()->assertSeeInOrder(['арбуз', 'Берёза', 'Яблоко']);
        $this->get('/articles?sort=zyx')->assertOk()->assertSeeInOrder(['Яблоко', 'Берёза', 'арбуз']);
        $this->get('/articles?sort=new')->assertOk()->assertSeeInOrder(['Берёза', 'Яблоко', 'арбуз']);
        $this->get('/articles?sort=old')->assertOk()->assertSeeInOrder(['арбуз', 'Яблоко', 'Берёза']);
    }

    public function test_sort_selector_defaults_to_newest_and_is_remembered(): void
    {
        $this->seedCore();

        $section = Section::where('slug', 'articles')->firstOrFail();
        for ($i = 1; $i <= 3; $i++) {
            Page::create([
                'section_id' => $section->id,
                'title' => "Статья {$i}",
                'body' => '<p>Тело</p>',
                'status' => 'published',
                'published_at' => "201{$i}-01-01",
            ]);
        }

        $response = $this->get('/articles')->assertOk();
        // В селекторе выбран вариант по умолчанию — «сначала новые»
        $response->assertSee('<option value="new" selected>', false);
        // Выбор запоминается в localStorage и применяется на других листингах
        $response->assertSee("localStorage.setItem('xi-sort', this.value)", false);
        $response->assertSee("localStorage.getItem('xi-sort')", false);
    }

    public function test_subsection_page_breadcrumbs_include_subsection(): void
    {
        $this->seedCore();

        $root = Section::where('slug', 'articles')->firstOrFail();
        $child = Section::create([
            'parent_id' => $root->id,
            'title' => 'Дайджесты',
            'slug' => 'daidzesty',
            'position' => 1,
            'is_visible' => true,
        ]);
        $page = Page::create([
            'section_id' => $child->id,
            'title' => 'Дайджест сентябрь',
            'body' => '<p>Тело…</p>',
            'status' => 'published',
        ]);

        // страница подраздела живёт под URL корневого раздела,
        // а в крошках между разделом и материалом — сам подраздел
        $this->get('/articles/'.$page->slug)
            ->assertOk()
            ->assertSeeInOrder(['Статьи', 'Дайджесты', 'Дайджест сентябрь'])
            ->assertSee('/articles/daidzesty', false);
    }

    public function test_admin_area_requires_authentication(): void
    {
        $this->seedCore();

        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_admin_area_requires_editor_role(): void
    {
        $this->seedCore();

        $outsider = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($outsider)->get('/admin')->assertForbidden();

        $admin = User::where('email', 'admin@x-intellect.org')->first();
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_editor_cannot_manage_redirects(): void
    {
        $this->seedCore();

        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->get('/admin/redirects')->assertForbidden();
        $this->actingAs($editor)->get('/admin/pages')->assertOk();
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_page_slug_is_transliterated_from_cyrillic_title(): void
    {
        $this->seedCore();

        $page = Page::create([
            'section_id' => Section::where('slug', 'wiki')->first()->id,
            'title' => 'Энергетический потенциал человека',
            'body' => '<p>Текст</p>',
            'status' => 'draft',
        ]);

        $this->assertSame('energeticeskii-potencial-celoveka', $page->slug);
        $this->assertNotEmpty($page->seo['meta_description']);
    }

    public function test_page_revision_is_created_on_update(): void
    {
        $this->seedCore();

        $page = Page::where('slug', 'o-sajte-x-intellect')->first();
        $page->update(['title' => 'О сайте X-INTELLECT (редакция 2026)']);

        $this->assertCount(1, $page->revisions);
        $this->assertSame('О сайте X-INTELLECT', $page->revisions->first()->title);
    }

    public function test_sitemap_command_generates_file(): void
    {
        $this->seedCore();

        @unlink(public_path('sitemap.xml'));

        $this->artisan('sitemap:generate')->assertSuccessful();

        $this->assertFileExists(public_path('sitemap.xml'));
        $this->assertStringContainsString('/fesoterika', file_get_contents(public_path('sitemap.xml')));

        GlossaryTerm::query()->exists(); // sanity: сидинг прошёл полностью
    }

    public function test_robots_and_llms_files_exist(): void
    {
        $this->assertFileExists(public_path('robots.txt'));
        $this->assertFileExists(public_path('llms.txt'));
        $this->assertStringContainsString('Disallow: /admin', file_get_contents(public_path('robots.txt')));
        $this->assertStringContainsString('/fesoterika', file_get_contents(public_path('llms.txt')));
    }

    public function test_image_alt_is_filled_from_title_on_save(): void
    {
        $this->seedCore();

        $page = Page::create([
            'section_id' => Section::where('slug', 'wiki')->first()->id,
            'title' => 'Энергетический двойник',
            'body' => '<p>До картинки</p><img src="/a.jpg"><p>между</p><img src="/b.jpg" alt="">',
            'status' => 'draft',
        ]);

        // Оба изображения получают описательный alt с названием и номером
        $this->assertStringContainsString('alt="Изображение к материалу «Энергетический двойник» - №1"', $page->body);
        $this->assertStringContainsString('alt="Изображение к материалу «Энергетический двойник» - №2"', $page->body);
    }

    public function test_image_alt_set_by_editor_is_preserved(): void
    {
        $this->seedCore();

        $page = Page::create([
            'section_id' => Section::where('slug', 'wiki')->first()->id,
            'title' => 'Тест',
            'body' => '<img src="/x.jpg" alt="Схема оболочечного двойника">',
            'status' => 'draft',
        ]);

        $this->assertStringContainsString('alt="Схема оболочечного двойника"', $page->body);
        $this->assertStringNotContainsString('Изображение к материалу', $page->body);
    }

    public function test_editor_image_upload_stores_file_and_returns_url(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = User::where('email', 'admin@x-intellect.org')->first();
        $file = \Illuminate\Http\Testing\File::image('photo.jpg', 400, 300);

        $page = Page::first();

        $response = $this->actingAs($admin)->post('/admin/editor/upload', [
            'file' => $file,
            'page_id' => $page->id,
        ]);

        $response->assertOk()->assertJsonStructure(['id', 'url', 'type']);
        // URL корне-относительный (/storage/…) — абсолютный из APP_URL ломается
        // при несовпадении хоста/порта (см. Media::url())
        $this->assertStringStartsWith('/storage/media/inline/', $response->json('url'));
        $this->assertSame('image', $response->json('type'));

        $stored = \Illuminate\Support\Facades\Storage::disk('public')->allFiles('media/inline');
        $this->assertCount(1, $stored);

        // Загрузка регистрируется в разделе «Медиа» с привязкой к странице
        $this->assertDatabaseHas('media', [
            'id' => $response->json('id'),
            'page_id' => $page->id,
            'type' => 'image',
            'title' => 'photo',
        ]);
    }

    public function test_editor_audio_upload_creates_media_of_type_audio(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = User::where('email', 'admin@x-intellect.org')->first();
        // валидный минимальный mp3-фрейм (MPEG-1 Layer III), чтобы finfo дал audio/mpeg
        $mp3 = "\xFF\xFB\x90\x00".str_repeat("\x00", 417);
        $file = \Illuminate\Http\Testing\File::createWithContent('session.mp3', $mp3);

        $response = $this->actingAs($admin)->post('/admin/editor/upload', ['file' => $file]);

        $response->assertOk();
        $this->assertSame('audio', $response->json('type'));
        $this->assertStringStartsWith('/storage/media/audio/', $response->json('url'));
        $this->assertDatabaseHas('media', [
            'id' => $response->json('id'),
            'type' => 'audio',
            'title' => 'session',
        ]);
    }

    public function test_editor_upload_rejects_unsupported_type(): void
    {
        $this->seedCore();
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = User::where('email', 'admin@x-intellect.org')->first();
        $file = \Illuminate\Http\Testing\File::createWithContent('script.exe', 'MZ binary');

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post('/admin/editor/upload', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_editor_upload_requires_auth(): void
    {
        $this->post('/admin/editor/upload', [])->assertRedirect('/login');
    }

    public function test_page_tables_survive_trix_editor_roundtrip(): void
    {
        $this->seedCore();
        $admin = User::where('email', 'admin@x-intellect.org')->first();

        $page = Page::first();
        // archive_wiki: регресс — PageRequest не пропускал этот source_type,
        // и вики-страницы молча не сохранялись (редирект назад без ошибок)
        $page->update([
            'body' => '<p>До таблицы</p><table><tr><td>Проект</td><td>Чакры</td></tr></table>',
            'source_type' => 'archive_wiki',
        ]);

        // Форма правки: таблица обёрнута в content-вложение Trix, иначе
        // редактор вырезал бы её при разборе HTML
        $this->actingAs($admin)
            ->get('/admin/pages/'.$page->slug.'/edit')
            ->assertOk()
            ->assertSee('vnd.xi-table', false);

        // Сохранение из редактора: figure с JSON → в БД чистый <table>
        $trixBody = app(\App\Services\TrixTables::class)->embed($page->fresh()->body);
        $this->actingAs($admin)->put('/admin/pages/'.$page->slug, [
            'title' => $page->title,
            'slug' => $page->slug,
            'section_id' => $page->section_id,
            'page_type' => $page->page_type,
            'status' => $page->status,
            'source_type' => $page->source_type,
            'is_listed' => '1',
            'body' => $trixBody,
        ])->assertRedirect();

        $page->refresh();
        $this->assertStringContainsString('<table>', $page->body);
        $this->assertStringContainsString('<td>Проект</td>', $page->body);
        $this->assertStringNotContainsString('data-trix-attachment', $page->body);
        // служебный contenteditable из embed() не протекает в БД
        $this->assertStringNotContainsString('contenteditable', $page->body);
        // и в публичном рендере таблица тоже на месте
        $this->assertStringContainsString('<table>', $page->body_rendered);
    }

    public function test_page_html_embeds_survive_trix_editor_roundtrip(): void
    {
        $this->seedCore();
        $admin = User::where('email', 'admin@x-intellect.org')->first();

        $iframe = '<iframe src="https://music.yandex.ru/iframe/playlist/42" width="600" height="450"></iframe>';
        $page = Page::first();
        $page->update(['body' => '<p>Плейлист:</p><div class="xi-embed">'.$iframe.'</div><!--/xi-embed-->']);

        // Форма правки: вставка свёрнута в content-вложение Trix — иначе
        // редактор вырезал бы iframe при разборе HTML
        $this->actingAs($admin)
            ->get('/admin/pages/'.$page->slug.'/edit')
            ->assertOk()
            ->assertSee('vnd.xi-embed', false)
            ->assertDontSee('<iframe', false);

        // Сохранение из редактора: figure с JSON → в БД снова блок с кодом
        $trixBody = app(\App\Services\TrixTables::class)->embed(
            app(\App\Services\TrixEmbeds::class)->embed($page->fresh()->body),
        );
        $this->actingAs($admin)->put('/admin/pages/'.$page->slug, [
            'title' => $page->title,
            'slug' => $page->slug,
            'section_id' => $page->section_id,
            'page_type' => $page->page_type,
            'status' => $page->status,
            'source_type' => $page->source_type,
            'is_listed' => '1',
            'body' => $trixBody,
        ])->assertRedirect();

        $page->refresh();
        $this->assertStringContainsString($iframe, $page->body);
        $this->assertStringNotContainsString('data-trix-attachment', $page->body);
        // и на публичной странице вставка на месте, кодом как есть
        $this->assertStringContainsString($iframe, $page->body_rendered);
        $this->get($page->url())->assertOk()->assertSee($iframe, false);
    }

    /** Вложение-вставка из редактора: JSON фигуры → блок тела. */
    private function embedFigure(string $code, ?string $alignment = null): string
    {
        $attrs = [
            'content' => '<div class="xi-embed-card"></div>',
            'contentType' => \App\Services\TrixEmbeds::CONTENT_TYPE,
            'code' => $code,
        ];
        if ($alignment !== null) {
            $attrs['alignment'] = $alignment;
        }

        return '<figure data-trix-attachment="'
            .htmlspecialchars(json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES)
            .'"></figure>';
    }

    public function test_trix_embeds_extract_allows_only_iframes(): void
    {
        $service = app(\App\Services\TrixEmbeds::class);

        // чужие вложения (картинки) не разворачиваются
        $img = '<figure data-trix-attachment="{&quot;contentType&quot;:&quot;image/png&quot;,&quot;url&quot;:&quot;/storage/x.png&quot;}"><img src="/storage/x.png"></figure>';
        $this->assertSame($img, $service->extract($img));

        // белый список: из вставки остаются только iframe с известными
        // атрибутами, обёртки и скрипты вырезаются
        $out = $service->extract($this->embedFigure(
            '<script src="https://vk.com/js/api/openapi.js"></script>'
            .'<div id="vk_playlist" onclick="play()">'
            .'<iframe src="https://vk.com/video_ext.php?oid=1" width="640" height="360" onload="hack()" allowfullscreen></iframe>'
            .'</div>',
        ));
        $this->assertSame(
            '<div class="xi-embed"><iframe src="https://vk.com/video_ext.php?oid=1" width="640" height="360" allowfullscreen></iframe></div><!--/xi-embed-->',
            $out,
        );

        // обратно в редактор: код уезжает в атрибут вложения (там он
        // экранирован — санитайзер Trix до тегов не доберётся), а повторное
        // сохранение возвращает тот же блок
        $back = $service->embed($out);
        $this->assertStringContainsString('vnd.xi-embed', $back);
        $this->assertStringNotContainsString('<iframe', $back);
        $this->assertSame($out, $service->extract($back));
    }

    public function test_trix_embeds_extract_rejects_dangerous_iframes(): void
    {
        $service = app(\App\Services\TrixEmbeds::class);

        // код без iframe блока не оставляет
        $this->assertSame('', $service->extract($this->embedFigure('<script>bad()</script>')));
        $this->assertSame('', $service->extract($this->embedFigure('  ')));

        // srcdoc и javascript:-src исполнились бы в origin сайта
        $this->assertSame('', $service->extract($this->embedFigure(
            '<iframe srcdoc="<script>parent.document.cookie</script>"></iframe>',
        )));
        $this->assertSame('', $service->extract($this->embedFigure(
            '<iframe src="javascript:alert(document.cookie)"></iframe>',
        )));
        $this->assertSame('', $service->extract($this->embedFigure(
            '<iframe src="data:text/html;base64,PHNjcmlwdD4="></iframe>',
        )));

        // у допущенного iframe srcdoc/on* не остаётся
        $out = $service->extract($this->embedFigure(
            '<iframe src="https://rutube.ru/play/embed/7" srcdoc="<script>x()</script>" onerror="hack()"></iframe>',
        ));
        $this->assertSame('<div class="xi-embed"><iframe src="https://rutube.ru/play/embed/7"></iframe></div><!--/xi-embed-->', $out);
    }

    public function test_trix_embeds_keep_alignment_chosen_in_editor(): void
    {
        $service = app(\App\Services\TrixEmbeds::class);
        $iframe = '<iframe src="https://rutube.ru/play/embed/7"></iframe>';

        // выравнивание из Trix → класс блока (те же классы, что у картинок)
        $out = $service->extract($this->embedFigure($iframe, 'left'));
        $this->assertSame('<div class="xi-embed xi-float-left">'.$iframe.'</div><!--/xi-embed-->', $out);

        // и обратно в редактор — чтобы кнопка выравнивания снова подсветилась
        $back = $service->embed($out);
        $this->assertStringContainsString('&quot;alignment&quot;:&quot;left&quot;', $back);
        $this->assertSame($out, $service->extract($back));

        // снятое выравнивание класса не оставляет
        $this->assertSame(
            '<div class="xi-embed">'.$iframe.'</div><!--/xi-embed-->',
            $service->extract($this->embedFigure($iframe, '')),
        );
    }

    public function test_float_image_before_table_renders_as_flex_pair(): void
    {
        $this->seedCore();
        $page = Page::first();

        // картинка с обтеканием прямо перед таблицей → пара в одну линию
        $page->update(['body' => '<img src="/storage/x.jpg" alt="Схема" class="xi-float-right">
<table><tr><td>Проект</td><td>Чакры</td></tr></table>
<p>Дальше текст</p><table><tr><td>Одинокая таблица</td></tr></table>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringContainsString('class="xi-imgtable xi-imgtable--right"', $rendered);
        // сырое тело остаётся без обвязки
        $this->assertStringNotContainsString('xi-imgtable', $page->fresh()->body);
        // таблица без картинки перед ней в пару не попадает
        $this->assertSame(1, substr_count($rendered, 'xi-imgtable xi-imgtable--'));
    }

    public function test_custom_404_page_with_search_and_noindex(): void
    {
        $this->seedCore();

        $response = $this->get('/takoy-stranicy-tochno-net');

        $response->assertNotFound()
            ->assertSee('Страница не найдена')
            ->assertSee('На главную');
        $this->assertStringContainsString('noindex', $response->getContent());
        $this->assertStringContainsString('action="'.url('/search').'"', $response->getContent());
    }

    public function test_blank_marker_link_renders_with_target_blank(): void
    {
        $this->seedCore();
        $page = Page::first();

        $page->update(['body' => '<div><a href="https://example.com/doc#_blank">внешняя</a> и <a href="https://example.com/plain">обычная</a></div>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringContainsString('href="https://example.com/doc" target="_blank" rel="noopener noreferrer"', $rendered);
        // обычная ссылка без маркера target не получает
        $this->assertStringNotContainsString('plain" target', $rendered);
        // сырое тело сохраняет маркер — редактор восстановит галочку
        $this->assertStringContainsString('#_blank', $page->fresh()->body);
    }

    public function test_file_attachment_renders_as_download_button(): void
    {
        $this->seedCore();
        $page = Page::first();

        $json = htmlspecialchars(json_encode([
            'contentType' => 'application/pdf',
            'filename' => 'kniga.pdf',
            'filesize' => 1820373,
            'href' => '/storage/media/pdf/kniga.pdf',
            'url' => '/storage/media/pdf/kniga.pdf',
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES);

        $page->update(['body' => '<div><a href="/storage/media/pdf/kniga.pdf"><figure data-trix-attachment="'.$json.'" class="attachment attachment--file">kniga.pdf</figure></a></div>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringContainsString('class="xi-download"', $rendered);
        $this->assertStringContainsString('Скачать', $rendered);
        $this->assertStringContainsString('kniga.pdf · 1,7 МБ', $rendered);
        $this->assertStringContainsString('href="/storage/media/pdf/kniga.pdf"', $rendered);
        // фигуры Trix в рендере не осталось
        $this->assertStringNotContainsString('attachment--file', $rendered);
    }

    public function test_image_figure_strips_empty_caption_and_opens_in_new_tab(): void
    {
        $this->seedCore();
        $page = Page::first();

        $json = htmlspecialchars(json_encode([
            'alignment' => 'left',
            'contentType' => 'image/webp',
            'filename' => 'cover.webp',
            'filesize' => 63668,
            'href' => '/storage/media/inline/cover.webp',
            'url' => '/storage/media/inline/cover.webp',
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES);

        $page->update(['body' => '<figure data-trix-attachment="'.$json.'" class="attachment attachment--preview">'
            .'<a href="/storage/media/inline/cover.webp"><img src="/storage/media/inline/cover.webp" alt="Обложка">'
            .'<figcaption class="attachment__caption"><span class="attachment__name">cover.webp</span>'
            .' <span class="attachment__size">62 KB</span></figcaption></a></figure>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringNotContainsString('attachment__name', $rendered);
        $this->assertStringNotContainsString('attachment__size', $rendered);
        $this->assertStringNotContainsString('<figcaption', $rendered);
        $this->assertStringContainsString('target="_blank"', $rendered);
        $this->assertStringContainsString('rel="noopener noreferrer"', $rendered);
    }

    public function test_image_figure_keeps_user_caption(): void
    {
        $this->seedCore();
        $page = Page::first();

        $page->update(['body' => '<figure class="attachment attachment--preview">'
            .'<img src="/storage/media/inline/cover.webp" alt="Обложка">'
            .'<figcaption class="attachment__caption"><span class="attachment__name">cover.webp</span>'
            .' <span class="attachment__size">62 KB</span> Авторская подпись</figcaption></figure>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringContainsString('<figcaption', $rendered);
        $this->assertStringContainsString('Авторская подпись', $rendered);
        $this->assertStringNotContainsString('attachment__name', $rendered);
        $this->assertStringContainsString('target="_blank"', $rendered);
    }

    public function test_archive_image_gets_link_to_open_in_new_tab(): void
    {
        $this->seedCore();
        $page = Page::first();

        $page->update(['body' => '<p><img src="/storage/media/archive/sample.png" alt="Иллюстрация" class="xi-float-left"></p>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringContainsString('<figure class="attachment attachment--preview xi-float-left">', $rendered);
        $this->assertStringContainsString('<a href="/storage/media/archive/sample.png"', $rendered);
        $this->assertStringContainsString('target="_blank"', $rendered);
    }

    public function test_archive_image_before_table_renders_as_flex_pair(): void
    {
        $this->seedCore();
        $page = Page::first();

        $page->update(['body' => '<figure class="attachment attachment--preview xi-float-right">'
            .'<img src="/storage/media/archive/scheme.jpg" alt="Схема"></figure>'
            .'<table><tr><td>Проект</td><td>Чакры</td></tr></table>']);

        $rendered = $page->fresh()->body_rendered;
        $this->assertStringContainsString('class="xi-imgtable xi-imgtable--right"', $rendered);
    }

    private function seedForumTopic(): \App\Models\ForumTopic
    {
        $topic = \App\Models\ForumTopic::create([
            'old_id' => 502,
            'forum_old_id' => 30,
            'forum_title' => 'Цивилизация «Зелёные»',
            'forum_group' => 'Исследования',
            'forum_position' => 10,
            'slug' => 'struktura-vselennoy',
            'title' => 'Структура Вселенной',
            'posts_count' => 2,
            'started_at' => '2012-08-11 03:26:00',
            'last_posted_at' => '2012-08-12 10:00:00',
        ]);
        $topic->posts()->createMany([
            ['old_id' => 635, 'author' => 'Max9003', 'posted_at' => '2012-08-11 03:26:00', 'body' => '<p>Вопрос о тёмной материи Вселенной.</p>', 'position' => 0],
            ['old_id' => 638, 'author' => 'Орлангур', 'posted_at' => '2012-08-12 10:00:00', 'body' => '<blockquote><p><strong>Max9003 писал(а):</strong></p>Вопрос</blockquote><p>Ответ по существу.</p>', 'position' => 1],
        ]);

        return $topic;
    }

    public function test_forum_archive_index_lists_topics_with_disclaimer(): void
    {
        $this->seedCore();
        $this->seedForumTopic();

        $this->get('/forum')
            ->assertOk()
            ->assertSee('Архив форума')
            ->assertSee('Структура Вселенной')
            ->assertSee('Цивилизация «Зелёные»')
            ->assertSee('архивная копия форума X-Intellect', false)
            ->assertSee('web.archive.org/web/20160408131611/http://www.x-intellect.org/forum/index.php', false)
            // никакого функционала регистрации/отправки
            ->assertDontSee('Регистрация</a>', false)
            ->assertDontSee('posting.php', false);
    }

    public function test_forum_topic_shows_posts_with_authors_and_schema(): void
    {
        $this->seedCore();
        $topic = $this->seedForumTopic();

        $this->get('/forum/'.$topic->slug)
            ->assertOk()
            ->assertSee('Структура Вселенной')
            ->assertSee('Max9003')
            ->assertSee('Орлангур')
            ->assertSee('Ответ по существу')
            ->assertSee('DiscussionForumPosting', false)  // SEO-разметка
            ->assertSee('Форум неактивен');
    }

    public function test_forum_search_finds_topics_by_title_and_post_body(): void
    {
        $this->seedCore();
        $topic = $this->seedForumTopic();

        // строка поиска — на главной форума, под дисклеймером
        $this->get('/forum')->assertOk()->assertSee('Поиск по форуму', false);

        // по заголовку, без учёта регистра
        $this->get('/forum/search?'.http_build_query(['q' => 'структура вселенной']))
            ->assertOk()
            ->assertSee('Структура Вселенной')
            ->assertSee($topic->url(), false);

        // по содержанию сообщений
        $this->get('/forum/search?'.http_build_query(['q' => 'ТЁМНОЙ МАТЕРИИ']))
            ->assertOk()
            ->assertSee('Структура Вселенной');

        // фрагмент подсказок — без общего layout
        $this->get('/forum/search?'.http_build_query(['q' => 'вселенной', 'partial' => 1]))
            ->assertOk()
            ->assertSee('Структура Вселенной')
            ->assertDontSee('site-header', false);

        // мимо
        $this->get('/forum/search?'.http_build_query(['q' => 'квазар']))
            ->assertOk()
            ->assertSee('тем не найдено');
    }

    public function test_forum_tile_on_home_when_topics_exist(): void
    {
        $this->seedCore();

        // без тем плитки нет
        $this->get('/')->assertOk()->assertDontSee('Архив форума');

        $this->seedForumTopic();
        $this->get('/')->assertOk()->assertSee('Архив форума');
    }

    public function test_old_forum_topic_url_redirects_to_new_slug(): void
    {
        $this->seedCore();
        $topic = $this->seedForumTopic();

        // редиректы, которые создаёт import:offline-forum (обе исторические формы)
        Redirect::create(['from_path' => '/forum/viewtopic.php?f=30&t=502', 'to_url' => $topic->url(), 'status_code' => 301, 'comment' => 'Архив форума: тест']);
        Redirect::create(['from_path' => '/forum/viewtopic.php?t=502', 'to_url' => $topic->url(), 'status_code' => 301, 'comment' => 'Архив форума: тест']);

        $this->get('/forum/viewtopic.php?f=30&t=502')->assertStatus(301)->assertRedirect($topic->url());
        $this->get('/forum/viewtopic.php?t=502')->assertStatus(301)->assertRedirect($topic->url());
    }

    public function test_phpbb_parser_extracts_posts_quotes_and_identity(): void
    {
        $html = <<<'HTML'
        <h2><a class="titles" href="viewtopic.php@f=30&t=502">Структура Вселенной</a></h2>
        <a name="p635"></a> <b class="postauthor">Max9003</b>
        <td class="gensmall"><b>Добавлено:</b> 11 авг 2012, 03:26</td>
        <div class="postbody">Первый вопрос о тёмной материи.</div>
        <a name="p638"></a> <b class="postauthor">Орлангур</b>
        <td class="gensmall"><b>Добавлено:</b> 12 авг 2012, 10:00</td>
        <div class="postbody"><div class="quotetitle">Max9003 писал(а):</div><div class="quotecontent">вопрос</div>Ответ по существу.</div>
        HTML;

        $page = app(\App\Services\PhpbbParser::class)->parsePage($html);

        $this->assertSame(30, $page['forum_id']);
        $this->assertSame(502, $page['topic_id']);
        $this->assertSame('Структура Вселенной', $page['title']);
        $this->assertCount(2, $page['posts']);
        $this->assertSame('Max9003', $page['posts'][635]['author']);
        $this->assertSame('Орлангур', $page['posts'][638]['author']);
        $this->assertSame('2012-08-11', $page['posts'][635]['posted_at']->format('Y-m-d'));
        // цитата phpBB → blockquote с подписью
        $this->assertStringContainsString('<blockquote>', $page['posts'][638]['body']);
        $this->assertStringContainsString('Ответ по существу', $page['posts'][638]['body']);
    }

    public function test_trix_tables_extract_sanitizes_and_keeps_other_attachments(): void
    {
        $service = app(\App\Services\TrixTables::class);

        // чужие вложения (картинки) не разворачиваются
        $img = '<figure data-trix-attachment="{&quot;contentType&quot;:&quot;image/png&quot;,&quot;url&quot;:&quot;/storage/x.png&quot;}"><img src="/storage/x.png"></figure>';
        $this->assertSame($img, $service->extract($img));

        // скрипты и on-атрибуты из таблицы вырезаются
        $dirty = json_encode([
            'content' => '<table><tr><td onclick="hack()">A<script>bad()</script></td></tr></table>',
            'contentType' => \App\Services\TrixTables::CONTENT_TYPE,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = $service->extract('<figure data-trix-attachment="'.htmlspecialchars($dirty, ENT_QUOTES).'"></figure>');
        $this->assertStringContainsString('<td>A</td>', $out);
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    public function test_privacy_policy_page_is_published(): void
    {
        $this->seedCore();

        $this->get('/about/politika-konfidencialnosti')
            ->assertOk()
            ->assertSee('Политика конфиденциальности')
            ->assertSee('localStorage', false);
    }

    public function test_cookie_policy_page_is_accessible_directly(): void
    {
        $this->seedCore();

        $this->get('/about/politika-cookies')
            ->assertOk()
            ->assertSee('Политика использования Cookies');
    }

    public function test_legal_pages_are_hidden_from_listings(): void
    {
        $this->seedCore();

        $legal = ['politika-konfidencialnosti', 'politika-cookies'];

        // «Последние материалы» на главной
        $home = collect($this->get('/')->viewData('latestPages'))->pluck('slug');
        foreach ($legal as $slug) {
            $this->assertFalse($home->contains($slug), "Главная не должна содержать {$slug}");
        }

        // Список раздела «О проекте»
        $section = collect($this->get('/about')->viewData('pages')->items())->pluck('slug');
        foreach ($legal as $slug) {
            $this->assertFalse($section->contains($slug), "Раздел не должен содержать {$slug}");
        }

        // Поиск
        $results = $this->get('/search?'.http_build_query(['q' => 'политика']))->viewData('results');
        $found = $results ? collect($results->items())->pluck('slug') : collect();
        foreach ($legal as $slug) {
            $this->assertFalse($found->contains($slug), "Поиск не должен возвращать {$slug}");
        }
    }

    public function test_legal_info_page_is_hidden_only_from_home_latest(): void
    {
        $this->seedCore();

        // «Правовая информация» создана в админке прода со свежей датой —
        // без исключения в HomeController возглавляла бы «Свежее»
        Page::create([
            'section_id' => Section::where('slug', 'rules')->firstOrFail()->id,
            'title' => 'Правовая информация',
            'slug' => 'pravovaia-informaciia',
            'body' => '<p>Дисклеймер</p>',
            'status' => 'published',
            'source_type' => 'new',
            'published_at' => now(),
        ]);

        $home = collect($this->get('/')->viewData('latestPages'))->pluck('slug');
        $this->assertFalse($home->contains('pravovaia-informaciia'), 'Страница не должна попадать в «Свежее»');

        // …но остаётся доступной, в листинге раздела и в поиске
        $this->get('/rules/pravovaia-informaciia')->assertOk();

        $section = collect($this->get('/rules')->viewData('pages')->items())->pluck('slug');
        $this->assertTrue($section->contains('pravovaia-informaciia'), 'Страница должна остаться в листинге раздела');

        $results = $this->get('/search?'.http_build_query(['q' => 'Правовая информация']))->viewData('results');
        $this->assertTrue(
            collect($results->items())->pluck('slug')->contains('pravovaia-informaciia'),
            'Страница должна находиться поиском'
        );
    }

    public function test_unlisted_page_hidden_from_listing_but_reachable(): void
    {
        $this->seedCore();

        $page = Page::create([
            'section_id' => Section::where('slug', 'wiki')->first()->id,
            'title' => 'Скрытая служебная страница',
            'body' => '<p>Тело</p>',
            'status' => 'published',
            'is_listed' => false,
        ]);

        // Не в списке раздела
        $slugs = collect($this->get('/wiki')->viewData('pages')->items())->pluck('slug');
        $this->assertFalse($slugs->contains($page->slug));

        // Но доступна по прямой ссылке
        $this->get('/wiki/'.$page->slug)->assertOk()->assertSee('Скрытая служебная страница');
    }

    public function test_og_image_defaults_to_logo_when_not_set(): void
    {
        $this->seedCore();

        // На странице без своего og_image подставляется логотип
        $this->get('/about/o-sajte-x-intellect')
            ->assertOk()
            ->assertSee('images/x-intellect_logo.webp', false);

        // На главной — тоже
        $this->get('/')
            ->assertOk()
            ->assertSee('property="og:image"', false)
            ->assertSee('images/x-intellect_logo.webp', false);
    }

    public function test_og_image_uses_custom_value_when_set(): void
    {
        $this->seedCore();

        $page = Page::where('slug', 'o-sajte-x-intellect')->first();
        $page->update(['seo' => array_merge($page->seo ?? [], ['og_image' => 'https://example.com/custom.jpg'])]);

        $this->get('/about/o-sajte-x-intellect')
            ->assertOk()
            ->assertSee('https://example.com/custom.jpg', false)
            ->assertDontSee('images/x-intellect_logo.webp', false);
    }

    public function test_year_list_is_tagged_as_timeline_on_save(): void
    {
        $this->seedCore();

        // Редактор Trix вырезает класс — он восстанавливается при сохранении,
        // если первый пункт начинается с <strong>ГОД
        $page = Page::create([
            'section_id' => Section::where('slug', 'about')->first()->id,
            'title' => 'Хронология',
            'body' => '<ul><li><strong>1982</strong> - начало</li><li><strong>1990</strong> - далее</li></ul>',
            'status' => 'published',
        ]);

        $this->assertStringContainsString('<ul class="timeline"', $page->body);
    }

    public function test_ordinary_list_is_not_tagged_as_timeline(): void
    {
        $this->seedCore();

        $page = Page::create([
            'section_id' => Section::where('slug', 'about')->first()->id,
            'title' => 'Обычный список',
            'body' => '<ul><li>первый пункт</li><li>второй</li></ul>',
            'status' => 'published',
        ]);

        $this->assertStringNotContainsString('timeline', $page->body);
    }
}
