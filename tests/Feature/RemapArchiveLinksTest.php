<?php

namespace Tests\Feature;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemapArchiveLinksTest extends TestCase
{
    use RefreshDatabase;

    private function makeWikiPage(string $title, string $slug, string $body): Page
    {
        $section = Section::firstOrCreate(['slug' => 'wiki'], ['title' => 'Вики', 'position' => 1]);

        return Page::create([
            'section_id' => $section->id,
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'status' => 'draft',
            'source_type' => 'archive_wiki',
        ]);
    }

    public function test_fragment_is_carried_to_new_wiki_url(): void
    {
        $this->makeWikiPage('Техники', 'texniki', '<p>Цель.</p>');
        // двойная кодировка OE: «Техники» = _25D0_25A2_25D0_25B5_25D1_2585_25D0_25BD_25D0_25B8_25D0_25BA_25D0_25B8
        $enc = str_replace('%', '_25', rawurlencode('Техники'));
        $src = $this->makeWikiPage('Исходная', 'isxodnaia',
            '<p><a href="index.php@title='.$enc.'#glava-2">Техники, глава 2</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="/wiki/texniki#glava-2"', $src->fresh()->body);
    }

    public function test_fragment_is_carried_to_main_site_url(): void
    {
        Redirect::create(['from_path' => '/opgry', 'to_url' => '/articles/opgry', 'status_code' => 301]);
        $src = $this->makeWikiPage('Источник', 'istocnik',
            '<p><a href="../opgry/default.htm#0001">Ссылка с якорем</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="/articles/opgry#0001"', $src->fresh()->body);
    }

    public function test_pure_fragment_link_is_untouched(): void
    {
        $src = $this->makeWikiPage('Стр', 'str', '<p><a href="#top">Наверх</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="#top"', $src->fresh()->body);
    }

    public function test_glossary_target_gets_no_fragment(): void
    {
        GlossaryTerm::create(['term' => 'Карма', 'slug' => 'karma', 'definition' => 'Определение.']);
        $enc = str_replace('%', '_25', rawurlencode('Карма'));
        $src = $this->makeWikiPage('Стр2', 'str2',
            '<p><a href="index.php@title='.$enc.'#x">Карма</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="/glossary?term=karma"', $src->fresh()->body);
    }

    public function test_wayback_wrapped_wiki_link_is_normalized(): void
    {
        $this->makeWikiPage('Сеансы 2016', 'seansy-2016', '<p>Список.</p>');
        $src = $this->makeWikiPage('Стр3', 'str3',
            '<p><a href="https://web.archive.org/web/20160408/http://www.x-intellect.org/wiki/index.php?title='.rawurlencode('Сеансы_2016').'">2016</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="/wiki/seansy-2016"', $src->fresh()->body);
    }

    public function test_raw_site_relative_wiki_link_is_remapped(): void
    {
        // сырой HTML из снимка id_: ссылки одинарной кодировки с ?title=
        $this->makeWikiPage('Сеанс с Силами 20140203', 'seans-s-silami-20140203', '<p>Текст.</p>');
        $src = $this->makeWikiPage('Стр6', 'str6',
            '<p><a href="/wiki/index.php?title='.rawurlencode('Сеанс_с_Силами_20140203').'#p2">сеанс</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="/wiki/seans-s-silami-20140203#p2"', $src->fresh()->body);
    }

    public function test_absolute_old_site_link_is_remapped(): void
    {
        Redirect::create(['from_path' => '/noosfera', 'to_url' => '/projects/nsf22', 'status_code' => 301]);
        $src = $this->makeWikiPage('Стр7', 'str7',
            '<p><a href="http://www.x-intellect.org/noosfera/">Ноосфера</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString('href="/projects/nsf22"', $src->fresh()->body);
    }

    public function test_wayback_external_link_is_untouched(): void
    {
        $href = 'https://web.archive.org/web/20150101/http://tululu.org/b81033/';
        $src = $this->makeWikiPage('Стр4', 'str4', '<p><a href="'.$href.'">Книга</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $this->assertStringContainsString($href, $src->fresh()->body);
    }

    public function test_dead_link_with_anchor_id_keeps_anchor(): void
    {
        $src = $this->makeWikiPage('Стр5', 'str5',
            '<p><a id="0002" href="../unknown-page/default.htm">Мёртвая ссылка</a></p>');

        $this->artisan('remap:archive-links')->assertSuccessful();

        $body = $src->fresh()->body;
        $this->assertStringContainsString('id="0002"', $body);
        $this->assertStringNotContainsString('href="../unknown-page', $body);
        $this->assertStringContainsString('Мёртвая ссылка', $body);
    }
}
