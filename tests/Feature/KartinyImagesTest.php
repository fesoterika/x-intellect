<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Странице «Картины Учителей Ноосферы» возвращаются картины из офлайн-слепка:
 * из веб-архива они не тянутся (внешние ссылки), а файлы лежат в слепке по
 * путям MediaWiki.
 */
class KartinyImagesTest extends TestCase
{
    use RefreshDatabase;

    /** Все картины страницы: путь в wiki/images (см. ContentFixes2026::KARTINY_GALLERIES). */
    private const FILES = [
        'a/a4/KN_2M.PNG', 'd/df/SH555.jpg', '2/2f/SH22.jpg', '1/10/SH23.jpg', '1/14/SH24.jpg',
        '6/6e/SH1.gif', 'a/a2/SH7.gif', '0/0c/SH8.gif', 'a/a6/SH9.gif',
        '0/0a/PM30.gif', 'e/ea/PM31.gif', '6/65/PM32.gif', 'a/ab/PM33.gif', 'a/af/34.gif', '7/7b/PM63.gif',
        '4/4f/G24.gif', 'd/d2/G25.gif', '5/55/G49.gif', 'e/ef/G50.gif',
        '0/00/37.gif', '8/81/36.gif', '4/40/PM62.gif',
    ];

    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // мини-слепок: пустышки по тем же путям, что и в офлайн-архиве
        $this->snapshot = sys_get_temp_dir().'/xi-snapshot-'.getmypid();
        foreach (self::FILES as $file) {
            $path = $this->snapshot.'/wiki/images/'.$file;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'GIF89a');
        }

        Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->snapshot);
        parent::tearDown();
    }

    /** Тело страницы после импорта из веб-архива: текст есть, картин нет. */
    private function makePage(): Page
    {
        return Page::create([
            'section_id' => Section::where('slug', 'wiki')->value('id'),
            'title' => 'Картины Учителей Ноосферы',
            'slug' => 'kartiny-ucitelei-noosfery',
            'status' => 'published',
            'source_type' => 'archive_wiki',
            'body' => '<div><table><tbody><tr><td>Проект</td></tr></tbody></table></div>'
                .'<div><strong>Структура банка Шамбалы</strong><br>УЧИТЕЛЯ:</div>'
                .'<ul><li>Подобные формы жизни уже существуют и на более высокой ступени развития.</li></ul>'
                .'<div><strong>Шамбала (12 картин)</strong> <br>'
                .'<strong>Параллельные миры (8 картин)</strong> <br>'
                .'<strong>Гармония (6 картин)</strong>&nbsp; <br> '
                .'<strong>Создание пятой расы на Земле (9 картин)</strong>&nbsp;</div>',
        ]);
    }

    public function test_command_inserts_all_paintings_and_copies_files(): void
    {
        $page = $this->makePage();

        $this->artisan('site:content-fixes-2026', ['--snapshot' => $this->snapshot])
            ->assertSuccessful();

        $page->refresh();

        preg_match_all('~<img[^>]+src="/storage/(media/archive/[^"]+)"~', $page->body, $m);
        $this->assertCount(23, $m[1], 'на странице 23 картины (SH1 повторяется в двух галереях)');

        foreach ($m[1] as $stored) {
            Storage::disk('public')->assertExists($stored);
        }

        // карточка проекта — пара «картинка + таблица», галереи — ряды
        $this->assertStringContainsString('xi-imgtable--right', (string) $page->body_rendered);
        $this->assertSame(5, substr_count((string) $page->body_rendered, 'class="xi-gallery"'));
    }

    public function test_command_is_idempotent_and_keeps_manual_edits(): void
    {
        $page = $this->makePage();

        $this->artisan('site:content-fixes-2026', ['--snapshot' => $this->snapshot])->assertSuccessful();
        $page->refresh();
        $first = $page->body;

        $this->artisan('site:content-fixes-2026', ['--snapshot' => $this->snapshot])
            ->expectsOutputToContain('картины уже на месте')
            ->assertSuccessful();

        $page->refresh();
        $this->assertSame($first, $page->body, 'повторный прогон не меняет тело');

        // текст, вычитанный вручную, остаётся на месте
        $this->assertStringContainsString('<strong>Структура банка Шамбалы</strong>', $page->body);
        $this->assertStringContainsString('Создание пятой расы на Земле (9 картин)', $page->body);
    }

    public function test_without_snapshot_page_is_left_untouched(): void
    {
        $page = $this->makePage();
        $before = $page->body;

        $this->artisan('site:content-fixes-2026')
            ->expectsOutputToContain('нужен путь к слепку')
            ->assertSuccessful();

        $this->assertSame($before, $page->refresh()->body);
    }
}
