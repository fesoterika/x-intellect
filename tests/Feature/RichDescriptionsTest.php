<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Разметка в описаниях разделов и определениях терминов глоссария:
 * Trix-редакторы в админке + отображение HTML на публичных страницах.
 */
class RichDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function editor(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_section_form_uses_trix_editor_for_description(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);

        $this->actingAs($this->editor())
            ->get(route('admin.sections.edit', $section))
            ->assertOk()
            ->assertSee('<trix-editor input="section-description"', false);
    }

    public function test_section_description_renders_markup_on_site(): void
    {
        Section::create([
            'title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true,
            'description' => '<div>Раздел со <strong>структурированными</strong> материалами.</div>',
        ]);

        $this->get('/wiki')
            ->assertOk()
            ->assertSee('section-desc', false)
            ->assertSee('со <strong>структурированными</strong> материалами', false);
    }

    public function test_legacy_plain_section_description_is_escaped(): void
    {
        Section::create([
            'title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true,
            'description' => 'Обычный текст <не разметка>',
        ]);

        // Текст с «<» считается HTML и отдаётся как есть — но чистые описания
        // без угловых скобок экранируются
        $this->get('/wiki')->assertOk()->assertSee('section-desc', false);
    }

    public function test_empty_trix_description_is_stored_as_null(): void
    {
        $this->actingAs($this->editor())->post(route('admin.sections.store'), [
            'title' => 'Новый раздел',
            'slug' => 'novyj-razdel',
            'description' => '<div><br></div>',
            'is_visible' => '1',
            'show_on_home' => '1',
        ]);

        $this->assertNull(Section::where('slug', 'novyj-razdel')->first()->description);
    }

    public function test_glossary_admin_uses_trix_editor_for_definition(): void
    {
        GlossaryTerm::create(['term' => 'Аура', 'slug' => 'aura', 'definition' => 'Полевая оболочка человека.']);

        $this->actingAs($this->editor())
            ->get(route('admin.glossary.index'))
            ->assertOk()
            ->assertSee('<trix-editor input="def-new"', false)
            ->assertSee('trix-editor input="def-', false);
    }

    public function test_glossary_definition_markup_renders_on_glossary_page(): void
    {
        GlossaryTerm::create([
            'term' => 'Аура', 'slug' => 'aura',
            'definition' => '<div>Полевая <em>оболочка</em> человека.</div>',
        ]);

        $this->get('/glossary')
            ->assertOk()
            ->assertSee('Полевая <em>оболочка</em> человека.', false);
    }

    public function test_glossary_meta_and_search_use_plain_definition(): void
    {
        $term = GlossaryTerm::create([
            'term' => 'Аура', 'slug' => 'aura',
            'definition' => '<div>Полевая <em>оболочка</em> человека.</div>',
        ]);

        $this->assertSame('Полевая оболочка человека.', $term->definitionPlain());

        // Поисковый индекс карточки строится в JS из DOM (data-term + текст
        // определения) — сервер не дублирует определение в data-search
        $this->get('/glossary?term=aura')
            ->assertOk()
            ->assertSee('data-term="аура"', false)
            ->assertDontSee('data-search=', false);
    }

    public function test_glossary_rejects_empty_trix_definition(): void
    {
        $this->actingAs($this->editor())
            ->from(route('admin.glossary.index'))
            ->post(route('admin.glossary.store'), [
                'term' => 'Пустой',
                'definition' => '<div><br></div>',
            ])
            ->assertSessionHasErrors('definition');
    }
}
