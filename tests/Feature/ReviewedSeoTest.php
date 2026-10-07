<?php

namespace Tests\Feature;

use App\Models\ForumTopic;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Section;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewedSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_metadata_does_not_replace_heading_menu_or_visible_description(): void
    {
        $description = str_repeat('Полное описание содержания. ', 8);
        $section = Section::create(['title' => 'Душа', 'slug' => 'dusha', 'is_visible' => true, 'description' => 'Прежнее описание раздела.', 'meta_title' => 'Душа: инкарнации и развитие — проект', 'meta_description' => $description]);
        $response = $this->get('/dusha');
        $response->assertOk()->assertSee('<title>Душа: инкарнации и развитие — проект</title>', false)
            ->assertSee('<meta name="description" content="'.$description.'">', false)
            ->assertSee('<h1 class="page-title">Душа</h1>', false)
            ->assertSee('Прежнее описание раздела.')
            ->assertSee('<link rel="canonical" href="'.rtrim(config('app.url'), '/').$section->url().'">', false);
        $this->assertSame('/dusha', $section->fresh()->url());
    }

    public function test_forum_and_term_descriptions_leave_archival_text_and_titles_intact(): void
    {
        $topic = ForumTopic::create(['old_id' => 500, 'forum_old_id' => 1, 'title' => 'Биоэкран', 'slug' => 'bioekran', 'forum_title' => 'Общий', 'posts_count' => 0, 'meta_description' => 'Архив обсуждения устройства биоэкрана.']);
        $term = GlossaryTerm::create(['term' => 'Биоэкран', 'slug' => 'bioekran', 'definition' => '<div>Прежнее полное определение.</div>', 'meta_description' => 'Биоэкран в авторской модели проекта.']);
        $this->get($topic->url())->assertOk()->assertSee('<h1 class="page-title">Биоэкран</h1>', false)
            ->assertSee('<meta name="description" content="Архив обсуждения устройства биоэкрана.">', false);
        $this->get($term->url())->assertOk()->assertSee('Биоэкран - глоссарий проекта X-Intellect')
            ->assertSee('<meta name="description" content="Биоэкран в авторской модели проекта.">', false)
            ->assertSee('<div>Прежнее полное определение.</div>', false);
        $this->assertSame('<div>Прежнее полное определение.</div>', $term->fresh()->definition);
    }

    public function test_root_metadata_only_changes_search_titles(): void
    {
        Setting::set('seo.home.meta_title', 'Архив ченнелингов X-Intellect');
        Setting::set('seo.glossary.meta_title', 'Термины и понятия X-Intellect');
        $this->get('/')->assertOk()->assertSee('<title>Архив ченнелингов X-Intellect</title>', false)
            ->assertSee('<h1 class="page-title">Информационный ресурс X-Intellect</h1>', false);
        $this->get('/glossary')->assertOk()->assertSee('<title>Термины и понятия X-Intellect</title>', false)
            ->assertSee('<h1 class="page-title">Глоссарий</h1>', false);
    }

    public function test_section_seo_fields_remain_editable_without_changing_the_url(): void
    {
        $section = Section::create(['title' => 'Душа', 'slug' => 'dusha', 'is_visible' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('admin.sections.update', $section), ['title' => 'Душа', 'slug' => 'dusha', 'is_visible' => 1, 'show_on_home' => 1, 'meta_title' => 'Душа: ченнелинги', 'meta_description' => 'Сеансы о развитии Души.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Душа: ченнелинги', $section->fresh()->meta_title);
        $this->assertSame('/dusha', $section->fresh()->url());
    }

    public function test_apply_is_dry_by_default_and_preserves_body_slug_and_other_seo_fields(): void
    {
        $page = $this->page();
        $page->refresh();
        $before = $page->getAttributes();
        $record = $this->record($page);
        $record['title'] = 'Прежнее название: уточнённая тема — ченнелинг';
        $record['meta_title'] = $record['title'];
        $record['description'] = 'Ответы Сил на вопросы о развитии человека.';
        $file = $this->manifest([$record]);
        $backup = $file.'.before';
        try {
            $this->artisan('seo:apply-review', ['manifest' => $file])->assertSuccessful();
            $this->assertSame($before, $page->fresh()->getAttributes());
            $this->artisan('seo:apply-review', ['manifest' => $file, '--apply' => true, '--backup' => $backup])->assertSuccessful();
            $after = $page->fresh();
            foreach (['slug', 'section_id', 'body', 'body_rendered', 'published_at'] as $field) {
                $this->assertSame($before[$field], $after->getAttributes()[$field]);
            }
            $this->assertSame($record['title'], $after->title);
            $this->assertSame($record['description'], $after->seoValue('meta_description'));
            $this->assertSame('https://example.org/cover.png', $after->seoValue('og_image'));
            $this->assertSame($record['url'], 'https://x-intellect.org'.$after->url());
            $this->assertSame($before['title'], $after->revisions()->first()->title);
            $this->assertFileExists($backup);
        } finally {
            @unlink($file);
            @unlink($backup);
        }
    }

    public function test_navigation_violation_prevents_the_entire_batch(): void
    {
        $ordinary = $this->page();
        $section = $ordinary->section;
        $protected = Page::unguarded(fn () => Page::create(['id' => 203, 'section_id' => $section->id, 'title' => 'Проекты 2005 - 2012', 'slug' => 'projects-2005-2012', 'body' => '<p>Старые ссылки</p>', 'status' => 'published']));
        $one = $this->record($ordinary);
        $one['title'] = $one['meta_title'] = 'Новое название';
        $two = $this->record($protected);
        $two['description'] = 'Запрещённое изменение.';
        $file = $this->manifest([$one, $two]);
        $backup = $file.'.before';
        try {
            $this->artisan('seo:apply-review', ['manifest' => $file, '--apply' => true, '--backup' => $backup])->assertFailed();
            $this->assertSame('Прежнее название', $ordinary->fresh()->title);
            $this->assertSame('<p>Старые ссылки</p>', $protected->fresh()->body);
            $this->assertFileDoesNotExist($backup);
        } finally {
            @unlink($file);
        }
    }

    public function test_stale_content_is_rejected_before_any_changes(): void
    {
        $page = $this->page();
        $record = $this->record($page);
        $record['content_sha256'] = hash('sha256', 'Старое содержимое');
        $record['title'] = 'Новое название';
        $file = $this->manifest([$record]);
        try {
            $this->artisan('seo:apply-review', ['manifest' => $file])->assertFailed();
            $this->assertSame('Прежнее название', $page->fresh()->title);
        } finally {
            @unlink($file);
        }
    }

    private function page(): Page
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
        return Page::create(['section_id' => $section->id, 'title' => 'Прежнее название', 'slug' => 'fixed-url', 'body' => '<p>Исходный архивный текст.</p>', 'status' => 'published', 'seo' => ['og_image' => 'https://example.org/cover.png']]);
    }

    private function record(Page $page): array
    {
        return ['kind' => 'page', 'id' => $page->id, 'old_title' => $page->title, 'title' => $page->title, 'meta_title' => $page->seoValue('meta_title'), 'description' => null, 'url' => 'https://x-intellect.org'.$page->url(), 'content_sha256' => hash('sha256', $page->body)];
    }

    private function manifest(array $records): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xi-seo-review-');
        file_put_contents($file, json_encode(['url_changes_allowed' => false, 'records' => $records]));
        return $file;
    }
}
