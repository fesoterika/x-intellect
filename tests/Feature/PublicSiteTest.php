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
            ->assertSee('Владелец этого сайта не является автором'); // дисклеймер в футере
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

        $this->get('/search?'.http_build_query(['q' => 'Глаз']))
            ->assertOk()
            ->assertSee('Найдено');
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

    public function test_section_shows_load_more_when_more_pages(): void
    {
        $this->seedCore();

        $section = Section::where('slug', 'mag')->first();
        for ($i = 1; $i <= 25; $i++) {
            Page::create([
                'section_id' => $section->id,
                'title' => "Статья номер {$i}",
                'body' => '<p>Тело статьи '.$i.'</p>',
                'status' => 'published',
            ]);
        }

        $this->get('/mag')
            ->assertOk()
            ->assertSee('Показать ещё')
            ->assertSee('class="load-more"', false)
            ->assertSee('page=2', false);
    }

    public function test_section_partial_returns_only_cards_without_layout(): void
    {
        $this->seedCore();

        $section = Section::where('slug', 'mag')->first();
        for ($i = 1; $i <= 25; $i++) {
            Page::create([
                'section_id' => $section->id,
                'title' => "Статья номер {$i}",
                'body' => '<p>Тело статьи '.$i.'</p>',
                'status' => 'published',
            ]);
        }

        $partial = $this->get('/mag?'.http_build_query(['page' => 2, 'partial' => 1]));

        $partial->assertOk()
            ->assertSee('section-items', false)   // фрагмент со списком карточек
            ->assertDontSee('site-header', false) // но без общего layout
            ->assertDontSee('Владелец этого сайта не является автором'); // без футера
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
        $this->assertStringContainsString('alt="Изображение к материалу «Энергетический двойник» №1"', $page->body);
        $this->assertStringContainsString('alt="Изображение к материалу «Энергетический двойник» №2"', $page->body);
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
