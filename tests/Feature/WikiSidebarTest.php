<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiSidebarTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_sidebar_shows_groups_with_pages_and_glossary_link(): void
    {
        [, $common, $seansy] = $this->makeWikiTree();
        $this->makePage($common, 'Правила Википедии', 'pravila-vikipedii', ['position' => 10]);
        $this->makePage($common, 'Техники', 'texniki', ['position' => 40]);
        $this->makePage($seansy, 'Сеансы 2013', 'seansy-2013', ['position' => 50]);

        $response = $this->get('/wiki')->assertOk();

        $response->assertSeeInOrder(['Общий раздел', 'Правила Википедии', 'Глоссарий', 'Техники', 'Сеансы', 'Сеансы 2013']);
        $response->assertSee('href="'.route('glossary').'"', false);
    }

    public function test_sidebar_lists_pages_beyond_first_paginator_page(): void
    {
        [$wiki, $common] = $this->makeWikiTree();
        // 25 страниц в корне — пагинатор по 20; страница меню должна быть видна всегда
        foreach (range(1, 25) as $i) {
            $this->makePage($wiki, 'Статья '.$i, 'statia-'.$i, ['position' => $i]);
        }
        $this->makePage($common, 'Библиотека', 'biblioteka', ['position' => 30]);

        $this->get('/wiki?page=2')->assertOk()->assertSee('Библиотека');
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
        $this->makePage($common, 'Техники', 'texniki', ['position' => 40]);
        $this->makePage($seansy, 'Сеансы 2013', 'seansy-2013', ['position' => 50]);

        $this->get('/wiki/seansy')->assertOk()
            ->assertSee('Страницы вики')
            ->assertSee('Техники');
    }
}
