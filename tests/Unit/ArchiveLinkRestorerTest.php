<?php

namespace Tests\Unit;

use App\Services\ArchiveLinkRestorer;
use PHPUnit\Framework\TestCase;

class ArchiveLinkRestorerTest extends TestCase
{
    private ArchiveLinkRestorer $restorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->restorer = new ArchiveLinkRestorer;
    }

    public function test_wraps_text_into_link(): void
    {
        $body = '<p>Смотри <strong>Сеансы 2011</strong> и другие материалы.</p>';
        $out = $this->restorer->insert($body, 'Сеансы 2011', '/wiki/seansy-2011');

        $this->assertSame(
            '<p>Смотри <strong><a href="/wiki/seansy-2011">Сеансы 2011</a></strong> и другие материалы.</p>',
            $out
        );
    }

    /** Главный инвариант: правка хирургическая — вне вставки тело не меняется. */
    public function test_body_outside_inserted_link_is_untouched(): void
    {
        $body = '<p>Текст с&nbsp;сущностями, &laquo;кавычками&raquo; и <br>переносами. Сеансы 2012 тут.</p>';
        $out = $this->restorer->insert($body, 'Сеансы 2012', '/wiki/seansy-2012');

        $withoutLink = str_replace(['<a href="/wiki/seansy-2012">', '</a>'], '', $out);
        $this->assertSame($body, $withoutLink);
    }

    public function test_matches_ignoring_case_yo_and_quotes(): void
    {
        $body = '<p>Проект «Учителя Ноосферы» описан ниже.</p>';
        $out = $this->restorer->insert($body, 'учителя ноосферы', '/wiki/uchitelya');

        $this->assertStringContainsString('<a href="/wiki/uchitelya">Учителя Ноосферы</a>', $out);
        $this->assertStringContainsString('«<a', $out, 'кавычки-ёлочки остаются снаружи ссылки');
    }

    public function test_matches_across_nbsp_entity(): void
    {
        $body = '<p>См. Сеансы&nbsp;2013 подробнее.</p>';
        $out = $this->restorer->insert($body, 'Сеансы 2013', '/wiki/seansy-2013');

        $this->assertStringContainsString('<a href="/wiki/seansy-2013">Сеансы&nbsp;2013</a>', $out);
    }

    public function test_does_not_nest_into_existing_link(): void
    {
        $body = '<p><a href="/old">Сеансы 2011</a></p>';

        $this->assertNull($this->restorer->insert($body, 'Сеансы 2011', '/wiki/seansy-2011'));
        $this->assertTrue($this->restorer->alreadyLinked($body, 'Сеансы 2011'));
    }

    public function test_wraps_only_first_occurrence(): void
    {
        $body = '<p>Сеансы 2011 и снова Сеансы 2011</p>';
        $out = $this->restorer->insert($body, 'Сеансы 2011', '/x');

        $this->assertSame(1, substr_count($out, '<a href='));
    }

    public function test_returns_null_when_text_absent(): void
    {
        $this->assertNull($this->restorer->insert('<p>Ничего</p>', 'Сеансы 2011', '/x'));
    }

    public function test_skips_figure_caption(): void
    {
        $body = '<figure><figcaption>Сеансы 2011</figcaption></figure><p>Сеансы 2011</p>';
        $out = $this->restorer->insert($body, 'Сеансы 2011', '/x');

        $this->assertSame(
            '<figure><figcaption>Сеансы 2011</figcaption></figure><p><a href="/x">Сеансы 2011</a></p>',
            $out
        );
    }

    public function test_escapes_href(): void
    {
        $out = $this->restorer->insert('<p>Ссылка тут</p>', 'Ссылка тут', '/glossary?term=a&b');

        $this->assertStringContainsString('href="/glossary?term=a&amp;b"', $out);
    }

    public function test_contains_text_checks_plain_text(): void
    {
        $body = '<p>Проект «Душа» — часть 1</p>';

        $this->assertTrue($this->restorer->containsText($body, 'Проект "Душа"'));
        $this->assertFalse($this->restorer->containsText($body, 'Проект Биоэкран'));
    }
}
