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

        $this->get('/wiki/index.php')->assertStatus(301);
    }

    public function test_glossary_page_renders_faq_schema(): void
    {
        $this->seedCore();

        $this->get('/glossarij')
            ->assertOk()
            ->assertSee('Биоэкран')
            ->assertSee('FAQPage', false);
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

        $this->assertLessThanOrEqual(20, substr_count($first->getContent(), 'page-card'));

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

        $response = $this->actingAs($admin)->post('/admin/editor/image', ['file' => $file]);

        $response->assertOk()->assertJsonStructure(['url']);

        $stored = \Illuminate\Support\Facades\Storage::disk('public')->allFiles('media/inline');
        $this->assertCount(1, $stored);
    }

    public function test_editor_image_upload_requires_auth(): void
    {
        $this->post('/admin/editor/image', [])->assertRedirect('/login');
    }

    public function test_privacy_policy_page_is_published(): void
    {
        $this->seedCore();

        $this->get('/about/politika-konfidencialnosti')
            ->assertOk()
            ->assertSee('Политика конфиденциальности')
            ->assertSee('localStorage', false);
    }
}
