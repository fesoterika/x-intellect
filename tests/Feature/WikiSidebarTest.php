<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WikiSidebarTest extends TestCase
{
    use RefreshDatabase;

    /** Только HTML бокового меню (aside) — отдельно от основного списка карточек. */
    private function sidebar(string $html): string
    {
        return Str::between($html, '<aside>', '</aside>');
    }

    private function makeWikiTree(): array
    {
        $wiki = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'position' => 1]);
        $common = Section::create(['title' => 'Общий раздел', 'slug' => 'obshhii-razdel', 'parent_id' => $wiki->id, 'position' => 1, 'show_on_home' => false]);
        $seansy = Section::create(['title' => 'Сеансы', 'slug' => 'seansy', 'parent_id' => $wiki->id, 'position' => 3, 'show_on_home' => false]);

        return [$wiki, $common, $seansy];
    }

    private function makePage(Section $section, string $title, string $slug, array $extra = []): Page
    {
        return Page::create(array_merge([
            'section_id' => $section->id,
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>Тело страницы для теста бокового меню вики.</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ], $extra));
    }

    public function test_sidebar_shows_only_marked_pages_after_fixed_links(): void
    {
        [, $common, $seansy] = $this->makeWikiTree();
        $this->makePage($common, 'Техники', 'texniki', ['position' => 40, 'in_wiki_menu' => true]);
        $this->makePage($seansy, 'Сеансы 2013', 'seansy-2013', ['position' => 50, 'in_wiki_menu' => true]);
        $this->makePage($common, 'Библиотека', 'biblioteka', ['position' => 5]); // не отмечена

        $sidebar = $this->sidebar($this->get('/wiki')->assertOk()->getContent());

        // Структурные ссылки: Общий раздел → Глоссарий, затем отмеченные страницы
        $this->assertNotFalse(strpos($sidebar, 'Общий раздел'));
        $this->assertStringContainsString('/wiki/obshhii-razdel', $sidebar);
        $this->assertStringContainsString(route('glossary'), $sidebar);
        $this->assertLessThan(strpos($sidebar, 'Глоссарий'), strpos($sidebar, 'Общий раздел'));
        $this->assertLessThan(strpos($sidebar, 'Техники'), strpos($sidebar, 'Глоссарий'));
        // Неотмеченная страница не выводится
        $this->assertStringNotContainsString('Библиотека', $sidebar);
    }

    public function test_sidebar_uses_mandatory_order_for_known_slugs(): void
    {
        [$wiki, $common, $seansy] = $this->makeWikiTree();
        // Отмечаем в «неправильном» порядке position — меню должно их пересортировать
        $this->makePage($seansy, 'Сеансы 2013', 'seansy-2013', ['position' => 1, 'in_wiki_menu' => true]);
        $this->makePage($common, 'Проекты 2005 - 2012', 'proekty-2005-2012', ['position' => 99, 'in_wiki_menu' => true]);
        $this->makePage($common, 'Техники', 'texniki', ['position' => 50, 'in_wiki_menu' => true]);

        $sidebar = $this->sidebar($this->get('/wiki')->assertOk()->getContent());

        // Обязательный порядок: Проекты 2005-2012 → Техники → Сеансы 2013
        $this->assertLessThan(strpos($sidebar, 'Техники'), strpos($sidebar, 'Проекты 2005 - 2012'));
        $this->assertLessThan(strpos($sidebar, 'Сеансы 2013'), strpos($sidebar, 'Техники'));
    }

    public function test_wiki_menu_starts_with_common_section_even_when_empty(): void
    {
        $this->makeWikiTree(); // ни одной отмеченной страницы

        $sidebar = $this->sidebar($this->get('/wiki')->assertOk()->getContent());

        $this->assertStringContainsString('Общий раздел', $sidebar);
        $this->assertStringContainsString('/wiki/obshhii-razdel', $sidebar);
    }

    public function test_sidebar_lists_marked_pages_beyond_first_paginator_page(): void
    {
        [$wiki, $common] = $this->makeWikiTree();
        // 25 страниц в корне — пагинатор по 20; меню (отмеченная страница) видно всегда
        foreach (range(1, 25) as $i) {
            $this->makePage($wiki, 'Статья '.$i, 'statia-'.$i, ['position' => $i]);
        }
        $this->makePage($common, 'Библиотека', 'biblioteka', ['position' => 30, 'in_wiki_menu' => true]);

        $this->get('/wiki?page=2')->assertOk()->assertSee('Библиотека');
    }

    public function test_sidebar_ignores_unmarked_and_unpublished_pages(): void
    {
        [, $common] = $this->makeWikiTree();
        $this->makePage($common, 'Черновик меню', 'chernovik-menu', ['status' => 'draft', 'in_wiki_menu' => true]);
        $this->makePage($common, 'Обычная страница', 'obychnaya', ['in_wiki_menu' => false]);

        $response = $this->get('/wiki')->assertOk();
        $response->assertSee('Страницы вики');

        $sidebar = $this->sidebar($response->getContent());
        $this->assertStringNotContainsString('Черновик меню', $sidebar);
        $this->assertStringNotContainsString('Обычная страница', $sidebar);
    }

    public function test_unlisted_transcript_hidden_from_lists_but_searchable_and_reachable(): void
    {
        [, , $seansy] = $this->makeWikiTree();
        $t = $this->makePage($seansy, 'Сеанс с Силами 20140203', 'seans-s-silami-20140203', [
            'is_listed' => false,
            'source_type' => 'archive_wiki', // архивные unlisted ищутся; new+unlisted (политики) — нет
        ]);

        $this->get('/wiki')->assertOk()->assertDontSee('Сеанс с Силами 20140203');
        $this->get('/wiki/seans-s-silami-20140203')->assertOk()->assertSee('Сеанс с Силами 20140203');
        $this->get('/search?q=20140203')->assertOk()->assertSee('Сеанс с Силами 20140203');
    }

    public function test_child_section_listing_shows_sidebar_too(): void
    {
        [, $common, $seansy] = $this->makeWikiTree();
        $this->makePage($common, 'Техники', 'texniki', ['position' => 40, 'in_wiki_menu' => true]);
        $this->makePage($seansy, 'Сеансы 2013', 'seansy-2013', ['position' => 50]);

        $this->get('/wiki/seansy')->assertOk()
            ->assertSee('Страницы вики')
            ->assertSee('Техники');
    }

    public function test_editor_form_has_wiki_menu_checkbox_unchecked_by_default(): void
    {
        $editor = User::factory()->create(['role' => 'admin']);

        $this->actingAs($editor)->get(route('admin.pages.create'))->assertOk()
            ->assertSee('Выводить в меню вики')
            ->assertSee('name="in_wiki_menu"', false)
            // Галочка по умолчанию не отмечена
            ->assertDontSee('name="in_wiki_menu" value="1" checked', false);
    }

    public function test_wiki_menu_flag_persists_from_editor(): void
    {
        $editor = User::factory()->create(['role' => 'admin']);
        $base = ['title' => 'Тест', 'page_type' => 'page', 'status' => 'published', 'source_type' => 'new'];

        // Без галочки — false (по умолчанию)
        $this->actingAs($editor)->post(route('admin.pages.store'), $base + ['slug' => 'test-off']);
        $this->assertFalse(Page::where('slug', 'test-off')->first()->in_wiki_menu);

        // С галочкой — true
        $this->actingAs($editor)->post(route('admin.pages.store'), $base + ['slug' => 'test-on', 'in_wiki_menu' => '1']);
        $this->assertTrue(Page::where('slug', 'test-on')->first()->in_wiki_menu);
    }
}
