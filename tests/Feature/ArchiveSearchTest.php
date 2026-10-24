<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveSearchTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $attributes): Page
    {
        static $number = 0;

        return Page::create(array_merge([
            'title' => 'Материал '.++$number, 'slug' => 'search-test-'.$number,
            'body' => '<p>Текст без совпадений.</p>', 'status' => 'published', 'is_listed' => true, 'source_type' => 'new',
        ], $attributes));
    }

    public function test_search_requires_all_words_across_title_excerpt_and_plain_body(): void
    {
        $both = $this->page(['title' => 'О биоэкране', 'body' => '<p>Хроно<strong>сфера</strong>&nbsp;в тексте</p>']);
        $excerpt = $this->page(['title' => 'Хроносфера', 'excerpt' => 'Работа биоэкрана']);
        $this->page(['title' => 'Только биоэкран']);
        $this->page(['title' => 'Только хроносфера']);
        $this->page(['title' => 'Биоэкран', 'body' => '<p class="хроносфера">Нет второго слова</p>']);

        $response = $this->get('/search?'.http_build_query(['q' => 'биоэкран хроносфер']));
        $response->assertOk()->assertSee('Ищем все слова')->assertSee('<mark class="search-match">биоэкран</mark>', false);
        $this->assertEqualsCanonicalizing([$both->id, $excerpt->id], $response->viewData('results')->pluck('id')->all());
    }

    public function test_raw_html_attributes_and_scripts_do_not_match(): void
    {
        $this->page(['body' => '<p class="маркер">Видимый текст</p><script>маркер</script><a href="/маркер">Ссылка</a>']);
        $visible = $this->page(['body' => '<p>Здесь маркер виден читателю.</p>']);
        $this->assertSame([$visible->id], $this->get('/search?'.http_build_query(['q' => 'маркер']))->assertOk()->viewData('results')->pluck('id')->all());
    }

    public function test_search_ranks_title_phrase_then_title_words_then_body_phrase(): void
    {
        $body = $this->page(['body' => '<p>биоэкран хроносфера</p>', 'published_at' => '2026-01-01']);
        $words = $this->page(['title' => 'Хроносфера и биоэкран', 'published_at' => '2025-01-01']);
        $phrase = $this->page(['title' => 'Биоэкран хроносфера', 'published_at' => '2024-01-01']);
        $results = $this->get('/search?'.http_build_query(['q' => 'биоэкран хроносфера']))->assertOk()->viewData('results');
        $this->assertSame([$phrase->id, $words->id, $body->id], $results->pluck('id')->all());
    }

    public function test_yo_case_literal_like_symbols_and_index_refresh(): void
    {
        $page = $this->page(['title' => 'ЁЛКА 100% xi_key', 'body' => '<p>Пример</p>']);
        $this->page(['title' => '1000 xiXkey']);
        foreach (['елка', '100%', 'xi_key'] as $query) {
            $response = $this->get('/search?'.http_build_query(['q' => $query]))->assertOk();
            $this->assertSame([$page->id], $response->viewData('results')->pluck('id')->all());
            $response->assertSee('class="search-match"', false);
        }
        $page->update(['title' => 'Другое название', 'excerpt' => 'Новый анонс', 'body' => '<p>Обновлённое содержание</p>']);
        $this->assertSame(0, $this->get('/search?'.http_build_query(['q' => 'елка']))->viewData('results')->total());
        $this->assertSame(1, $this->get('/search?'.http_build_query(['q' => 'обновленное']))->viewData('results')->total());
    }

    public function test_drafts_and_service_pages_stay_hidden_but_archive_transcripts_are_searchable(): void
    {
        $this->page(['title' => 'Контрольный запрос', 'status' => 'draft']);
        $this->page(['title' => 'Контрольный запрос', 'is_listed' => false]);
        $archive = $this->page(['title' => 'Контрольный запрос', 'is_listed' => false, 'source_type' => 'archive_xintellect']);
        $this->assertSame([$archive->id], $this->get('/search?'.http_build_query(['q' => 'контрольный']))->assertOk()->viewData('results')->pluck('id')->all());
    }

    public function test_glossary_uses_same_word_matching_and_context_snippets(): void
    {
        GlossaryTerm::create(['term' => 'Ёлка', 'slug' => 'elka', 'definition' => '<p>'.str_repeat('Длинное определение. ', 30).'Контрольный термин</p>']);
        $this->get('/search?'.http_build_query(['q' => 'елка термин']))->assertOk()
            ->assertSee('В глоссарии: 1')->assertSee('<mark class="search-match">Ёлка</mark>', false)
            ->assertSee('<mark class="search-match">термин</mark>', false);
    }

    public function test_malformed_and_excessive_queries_are_rejected_without_server_error(): void
    {
        $this->getJson('/search?q[]=text')->assertUnprocessable();
        $this->getJson('/search?'.http_build_query(['q' => str_repeat('а', 201)]))->assertUnprocessable();
        $this->get('/search?q=%21%21')->assertOk()->assertSee('Введите не менее двух символов');
    }
}
