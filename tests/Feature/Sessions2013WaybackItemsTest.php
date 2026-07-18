<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Указатель «Сеансы 2013» дополняется пунктами из снимка Wayback 07.12.2021:
 * стенограмм этих сеансов веб-архив не снимал, поэтому ссылки ведут на
 * сохранившиеся записи основного сайта, а старые wiki-адреса получают 301.
 */
class Sessions2013WaybackItemsTest extends TestCase
{
    use RefreshDatabase;

    private Page $index;

    protected function setUp(): void
    {
        parent::setUp();

        $wiki = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
        $projects = Section::create(['title' => 'Проекты', 'slug' => 'projects', 'is_visible' => true]);

        $this->index = Page::create([
            'section_id' => $wiki->id,
            'title' => 'Сеансы 2013',
            'slug' => 'seansy-2013',
            'status' => 'published',
            'source_type' => 'archive_wiki',
            'body' => '<figure>картинка пользователя</figure>'
                .'<ul><li><a href="/wiki/seans-s-silami-20131123"><strong>Сеанс с Силами 20131123</strong></a></li></ul>',
        ]);

        foreach ([
            ['Проект «Ноосфера - 7» Кто такие Учителя', 'proekt-noosfera-7-kto-takie-uchitelya', $projects],
            ['Проект «Ноосфера -9» Взаимодействие Ноосферы с людьми', 'proekt-noosfera-9-vzaimodejstvie-noosfery-s-lyud-mi', $projects],
            ['Аудиозапись 20131125', 'audiozapis-20131125', $wiki],
        ] as [$title, $slug, $section]) {
            Page::create([
                'section_id' => $section->id,
                'title' => $title,
                'slug' => $slug,
                'status' => 'draft',
                'source_type' => 'archive_xintellect',
                'body' => '<p>тело</p>',
            ]);
        }
    }

    public function test_missing_items_are_appended_before_last_ul_close(): void
    {
        $this->artisan('site:content-fixes-2026')->assertExitCode(0);

        $body = $this->index->fresh()->body;

        // существующее тело не тронуто, пункты — внутри списка
        $this->assertStringContainsString('<figure>картинка пользователя</figure>', $body);
        $this->assertStringContainsString('seans-s-silami-20131123', $body);
        $this->assertStringContainsString('/projects/proekt-noosfera-7-kto-takie-uchitelya"><strong>Сеанс с Силами 20130721', $body);
        $this->assertStringContainsString('/projects/proekt-noosfera-9-vzaimodejstvie-noosfery-s-lyud-mi"><strong>Сеанс с Силами 20131110', $body);
        $this->assertStringContainsString('/wiki/audiozapis-20131125"><strong>&nbsp;- ДОРОГА В НЕБО', $body);
        $this->assertSame(1, substr_count($body, '</ul>'));
        $this->assertStringEndsWith('</ul>', $body);
    }

    public function test_rerun_does_not_duplicate_items(): void
    {
        $this->artisan('site:content-fixes-2026');
        $body = $this->index->fresh()->body;

        $this->artisan('site:content-fixes-2026');

        $this->assertSame($body, $this->index->fresh()->body);
    }

    public function test_old_wiki_paths_get_301_and_existing_redirect_is_kept(): void
    {
        Redirect::create([
            'from_path' => '/wiki/index.php?title=Аудиозапись_20131125',
            'to_url' => '/wiki/audiozapis-20131125',
            'status_code' => 301,
            'comment' => 'из импорта вики',
        ]);

        $this->artisan('site:content-fixes-2026');

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/wiki/index.php?title=Сеанс_с_Силами_20130721',
            'to_url' => '/projects/proekt-noosfera-7-kto-takie-uchitelya',
            'status_code' => 301,
        ]);
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/wiki/index.php?title=Сеанс с Силами 20131110',
            'to_url' => '/projects/proekt-noosfera-9-vzaimodejstvie-noosfery-s-lyud-mi',
        ]);
        // существующий редирект не перезаписан
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/wiki/index.php?title=Аудиозапись_20131125',
            'comment' => 'из импорта вики',
        ]);
    }

    public function test_item_is_skipped_when_date_already_in_body(): void
    {
        $this->index->update([
            'body' => '<ul><li><strong>Сеанс с Силами 20130721</strong></li></ul>',
        ]);

        $this->artisan('site:content-fixes-2026');

        $this->assertSame(1, substr_count($this->index->fresh()->body, '20130721'));
    }
}
