<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Дисклеймер материала (pages.disclaimer): неброская приписка внизу страницы
 * под плашкой «Нашли ошибку?», редактируется в админ-форме материала.
 */
class PageDisclaimerTest extends TestCase
{
    use RefreshDatabase;

    private function makePage(array $overrides = []): Page
    {
        $section = Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 1]);

        return Page::create(array_merge([
            'section_id' => $section->id,
            'title' => 'Материал',
            'slug' => 'material',
            'body' => '<p>Текст.</p>',
            'status' => 'published',
        ], $overrides));
    }

    private function pageForm(Page $page, array $overrides = []): array
    {
        return array_merge([
            'section_id' => $page->section_id,
            'title' => $page->title,
            'slug' => $page->slug,
            'body' => $page->body,
            'page_type' => 'page',
            'status' => 'published',
            'source_type' => 'new',
        ], $overrides);
    }

    public function test_disclaimer_renders_below_feedback_block(): void
    {
        $this->seed();
        $this->makePage(['disclaimer' => 'Материал не является медицинской консультацией.']);

        $html = $this->get('/articles/material')
            ->assertOk()
            ->assertSee('Материал не является медицинской консультацией.')
            ->getContent();

        // Приписка стоит ПОД плашкой «Нашли ошибку?»
        $this->assertGreaterThan(
            strpos($html, 'Нашли ошибку?'),
            strpos($html, 'page-disclaimer'),
        );
    }

    public function test_page_without_disclaimer_has_no_note(): void
    {
        $this->seed();
        $this->makePage();

        $this->get('/articles/material')
            ->assertOk()
            ->assertDontSee('page-disclaimer');
    }

    public function test_editor_edits_disclaimer_without_creating_a_revision(): void
    {
        $page = $this->makePage();
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)
            ->put(route('admin.pages.update', $page), $this->pageForm($page, [
                'disclaimer' => 'Оценочные суждения авторов.',
            ]))
            ->assertRedirect();

        $page->refresh();
        $this->assertSame('Оценочные суждения авторов.', $page->disclaimer);
        // Ревизии создаются только при правке title/body — дисклеймер их не плодит
        $this->assertSame(0, $page->revisions()->count());

        // Пустое поле очищает приписку
        $this->actingAs($editor)
            ->put(route('admin.pages.update', $page), $this->pageForm($page, ['disclaimer' => '']))
            ->assertRedirect();

        $this->assertNull($page->refresh()->disclaimer);
    }
}
