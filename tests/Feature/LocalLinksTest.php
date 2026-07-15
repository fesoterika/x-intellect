<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Services\LocalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Абсолютные ссылки на localhost в контенте превращаются в относительные —
 * при каждом сохранении из админки и сервисом LocalLinks.
 */
class LocalLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_relativizes_href_and_src_variants(): void
    {
        $links = new LocalLinks;

        $this->assertSame(
            '<a href="/wiki/aura#_blank">т</a><img src="/storage/x.jpg"><a href="/">гл</a>',
            $links->relativize(
                '<a href="http://localhost:8753/wiki/aura#_blank">т</a>'
                .'<img src="https://127.0.0.1/storage/x.jpg">'
                .'<a href="http://localhost:8753">гл</a>',
            ),
        );

        // Текст со словом localhost и чужие домены не трогаются
        $html = '<p>про localhost</p><a href="https://example.com/localhost/x">в</a>';
        $this->assertSame($html, $links->relativize($html));
    }

    public function test_page_body_is_relativized_on_save(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);

        $page = Page::create([
            'section_id' => $section->id,
            'title' => 'Тест ссылок',
            'body' => '<div><a href="http://localhost:8753/glossary?term=aura">Аура</a></div>',
            'status' => 'draft',
        ]);

        $this->assertStringContainsString('href="/glossary?term=aura"', $page->body);
        $this->assertStringNotContainsString('localhost', $page->body);
        $this->assertStringNotContainsString('localhost', (string) $page->body_rendered);
    }

    public function test_glossary_definition_is_relativized_on_save(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.glossary.store'), [
            'term' => 'Аура',
            'definition' => '<div>См. <a href="http://localhost:8753/wiki/polevaia-obolocka">оболочку</a></div>',
        ]);

        $term = GlossaryTerm::where('term', 'Аура')->first();
        $this->assertStringContainsString('href="/wiki/polevaia-obolocka"', $term->definition);
    }

    public function test_section_description_is_relativized_on_save(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.sections.store'), [
            'title' => 'Тестовый раздел',
            'slug' => 'testovyj-razdel',
            'description' => '<div><a href="http://localhost:8753/library">книги</a></div>',
            'is_visible' => '1',
            'show_on_home' => '1',
        ]);

        $section = Section::where('slug', 'testovyj-razdel')->first();
        $this->assertStringContainsString('href="/library"', $section->description);
    }

    public function test_glossary_slug_is_stable_on_rename(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $term = GlossaryTerm::create(['term' => 'Аура', 'slug' => 'aura', 'definition' => 'Оболочка.']);

        // Переименование не меняет адрес термина /glossary?term=aura
        $this->actingAs($admin)->put(route('admin.glossary.update', $term), [
            'term' => 'Аура (полевая)',
            'definition' => 'Оболочка.',
        ]);

        $this->assertSame('aura', $term->fresh()->slug);
    }
}
