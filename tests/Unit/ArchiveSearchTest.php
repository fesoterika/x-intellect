<?php

namespace Tests\Unit;

use App\Services\ArchiveSearch;
use App\Support\SearchText;
use PHPUnit\Framework\TestCase;

class ArchiveSearchTest extends TestCase
{
    public function test_plain_text_keeps_word_boundaries_and_ignores_markup_metadata(): void
    {
        $this->assertSame('Биоэкран и Хроносфера A & B', SearchText::plain(
            '<p class="секрет">Био<strong>экран</strong>&nbsp;и</p><p>Хроносфера<br>A &amp; B</p><script>скрытый</script>[[audio:123]]',
        ));
    }

    public function test_highlight_handles_cyrillic_case_yo_and_escapes_html(): void
    {
        $search = new ArchiveSearch('еЛка');
        $this->assertSame('&lt;img onerror=&quot;x&quot;&gt; <mark class="search-match">ЁЛКА</mark> &amp; <mark class="search-match">елка</mark>',
            (string) $search->highlight('<img onerror="x"> ЁЛКА & елка'));
    }

    public function test_query_requires_all_terms_but_allows_substrings_and_reversed_order(): void
    {
        $search = new ArchiveSearch('биоэкран, хроносфер');
        $this->assertTrue($search->matches('Хроносфера и работа БИОЭКРАНА'));
        $this->assertFalse($search->matches('Только биоэкран'));
        $this->assertSame('<mark class="search-match">биоэкран хроносфера</mark>', (string) (new ArchiveSearch('биоэкран хроносфера'))->highlight('биоэкран хроносфера'));
    }

    public function test_snippet_finds_deep_match_and_distant_terms_instead_of_generic_excerpt(): void
    {
        $search = new ArchiveSearch('биоэкран хроносфер');
        $snippet = (string) $search->snippet('<p>'.str_repeat('Вступление без совпадений. ', 40).'БИОЭКРАН в контексте. '.str_repeat('Дальнейший текст. ', 50).'Хроносфера завершает материал.</p>', 'Обычный анонс');
        $this->assertStringContainsString('<mark class="search-match">БИОЭКРАН</mark>', $snippet);
        $this->assertStringContainsString('<mark class="search-match">Хроносфер</mark>а', $snippet);
        $this->assertStringNotContainsString('Обычный анонс', $snippet);
        $this->assertLessThan(500, mb_strlen(strip_tags($snippet)));
    }

    public function test_snippet_uses_excerpt_when_only_excerpt_matches_and_escapes_entities(): void
    {
        $snippet = (string) (new ArchiveSearch('биоэкран'))->snippet('<p>Иное содержание</p>', 'Биоэкран &lt;script&gt;alert(1)&lt;/script&gt;');
        $this->assertStringContainsString('<mark class="search-match">Биоэкран</mark>', $snippet);
        $this->assertStringContainsString('&lt;script&gt;', $snippet);
        $this->assertStringNotContainsString('<script>', $snippet);
    }
}
