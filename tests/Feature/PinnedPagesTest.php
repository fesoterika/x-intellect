<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Закрепление материалов (is_pinned) и чекбоксы фильтра в админке.
 */
class PinnedPagesTest extends TestCase
{
    use RefreshDatabase;

    private Section $articles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = Section::firstOrCreate(
            ['slug' => 'articles'],
            ['title' => 'Статьи', 'position' => 1, 'is_visible' => true],
        );
    }

    private function makePage(string $title, array $attrs = []): Page
    {
        return Page::create(array_merge([
            'section_id' => $this->articles->id,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title),
            'body' => '<p>Текст.</p>',
            'status' => 'published',
            'is_listed' => true,
            'published_at' => now()->subYear(),
        ], $attrs));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Закреплённый идёт первым, хотя по алфавиту должен быть последним. */
    public function test_pinned_page_leads_section_listing(): void
    {
        $this->makePage('Альфа');
        $this->makePage('Бета');
        $this->makePage('Яшма', ['is_pinned' => true]);

        $response = $this->get('/articles?sort=abc');

        $response->assertOk();
        $this->assertLessThan(
            strpos($response->getContent(), 'Альфа'),
            strpos($response->getContent(), 'Яшма'),
            'Закреплённая страница должна идти выше по списку.',
        );
    }

    /** Между собой закреплённые следуют выбранной сортировке, а не порядку id. */
    public function test_pinned_pages_keep_chosen_sort_between_themselves(): void
    {
        $this->makePage('Яшма', ['is_pinned' => true]);
        $this->makePage('Азалия', ['is_pinned' => true]);
        $this->makePage('Обычная');

        $html = $this->get('/articles?sort=abc')->getContent();

        $this->assertLessThan(strpos($html, 'Яшма'), strpos($html, 'Азалия'));
        $this->assertLessThan(strpos($html, 'Обычная'), strpos($html, 'Яшма'));
    }

    /** Значок булавки — только у закреплённой карточки. */
    public function test_pin_icon_rendered_only_for_pinned_card(): void
    {
        $this->makePage('Обычная');

        $this->get('/articles')->assertDontSee('page-card-pin');

        $this->makePage('Закреплённая', ['is_pinned' => true]);

        $this->get('/articles')
            ->assertSee('page-card-pin')
            ->assertSee('Закреплённый материал');
    }

    /** В админке закреплённые тоже сверху, несмотря на сортировку по дате правки. */
    public function test_admin_list_puts_pinned_first(): void
    {
        $old = $this->makePage('Старая', ['is_pinned' => true]);
        $old->forceFill(['updated_at' => now()->subMonth()])->saveQuietly();
        $this->makePage('Свежая');

        $html = $this->actingAs($this->admin())->get(route('admin.pages.index'))->getContent();

        $this->assertLessThan(strpos($html, 'Свежая'), strpos($html, 'Старая'));
    }

    /** «НЕ показывается» — выборка скрытых из списков, для их вычитки. */
    public function test_admin_filter_by_unlisted_checkbox(): void
    {
        $this->makePage('Видимая');
        $this->makePage('Скрытая', ['is_listed' => false]);

        $this->actingAs($this->admin())
            ->get(route('admin.pages.index', ['unlisted' => 1]))
            ->assertSee('Скрытая')
            ->assertDontSee('Видимая');
    }

    public function test_admin_filter_by_wiki_menu_checkbox(): void
    {
        $this->makePage('В меню', ['in_wiki_menu' => true]);
        $this->makePage('Не в меню');

        $this->actingAs($this->admin())
            ->get(route('admin.pages.index', ['wiki_menu' => 1]))
            ->assertSee('В меню')
            ->assertDontSee('Не в меню');
    }

    /** Снятые чекбоксы фильтр не сужают. */
    public function test_unchecked_filters_show_everything(): void
    {
        $this->makePage('Видимая');
        $this->makePage('Скрытая', ['is_listed' => false]);

        $this->actingAs($this->admin())
            ->get(route('admin.pages.index'))
            ->assertSee('Видимая')
            ->assertSee('Скрытая');
    }

    /** Галочка в форме правки сохраняется и снимается. */
    public function test_checkbox_in_form_toggles_pin(): void
    {
        $page = $this->makePage('Материал');

        $payload = [
            'section_id' => $this->articles->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'body' => $page->body,
            'page_type' => 'page',
            'status' => 'published',
            'source_type' => 'new',
            'is_listed' => '1',
            'is_pinned' => '1',
        ];

        $this->actingAs($this->admin())->put(route('admin.pages.update', $page), $payload);
        $this->assertTrue($page->fresh()->is_pinned);

        $this->actingAs($this->admin())->put(route('admin.pages.update', $page), array_merge($payload, ['is_pinned' => '0']));
        $this->assertFalse($page->fresh()->is_pinned);
    }
}
