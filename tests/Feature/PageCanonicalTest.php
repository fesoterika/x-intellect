<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Canonical страниц не запекается в seo (аудит 18.07.2026: у всех страниц
 * лежал http://localhost/… от dev-автозаполнения). Шаблоны строят canonical
 * от текущего APP_URL на лету; в поле живёт только ручное значение из
 * админ-формы. Запечённые localhost-значения подчищает site:content-fixes-2026.
 */
class PageCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private Section $wiki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wiki = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
    }

    private function makePage(string $slug = 'material', array $attrs = []): Page
    {
        return Page::create($attrs + [
            'section_id' => $this->wiki->id,
            'title' => 'Материал',
            'slug' => $slug,
            'body' => '<p>Текст.</p>',
            'status' => 'published',
        ]);
    }

    public function test_fill_defaults_does_not_store_canonical(): void
    {
        $page = $this->makePage();

        $this->assertArrayNotHasKey('canonical', $page->fresh()->seo);
        $this->assertNotEmpty($page->fresh()->seo['meta_description']);
    }

    public function test_page_without_canonical_renders_it_from_app_url(): void
    {
        $page = $this->makePage();
        $expected = rtrim(config('app.url'), '/').'/wiki/material';

        $this->get($page->url())
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$expected.'">', false)
            ->assertSee('<meta property="og:url" content="'.$expected.'">', false);
    }

    public function test_manual_canonical_is_rendered_as_is(): void
    {
        $page = $this->makePage();
        $page->update(['seo' => array_merge($page->seo ?? [], ['canonical' => 'https://example.org/pervoistocnik'])]);

        $this->get($page->url())
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://example.org/pervoistocnik">', false);
    }

    public function test_content_fixes_clears_localhost_canonicals(): void
    {
        $baked = $this->makePage('zapecennaya');
        $baked->forceFill(['seo' => [
            'meta_title' => 'Свой заголовок',
            'canonical' => 'http://localhost/wiki/zapecennaya',
        ]])->saveQuietly();

        $manual = $this->makePage('rucnaya');
        $manual->forceFill(['seo' => ['canonical' => 'https://example.org/pervoistocnik']])->saveQuietly();

        $this->artisan('site:content-fixes-2026')->assertSuccessful();

        // localhost-canonical снят, соседние seo-ключи и тело published целы
        $baked = $baked->fresh();
        $this->assertNull($baked->seoValue('canonical'));
        $this->assertSame('Свой заголовок', $baked->seoValue('meta_title'));
        $this->assertSame('<p>Текст.</p>', $baked->body);

        // ручной canonical с чужим хостом не тронут
        $this->assertSame('https://example.org/pervoistocnik', $manual->fresh()->seoValue('canonical'));

        // идемпотентность: повторный прогон ничего не находит
        $this->artisan('site:content-fixes-2026')
            ->expectsOutputToContain('Canonical с localhost: очищено — 0.')
            ->assertSuccessful();
    }
}
