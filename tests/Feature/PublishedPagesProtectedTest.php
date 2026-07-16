<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Опубликованное вычитано пользователем вручную: команды доимпорта не имеют
 * права переписывать его тело. Гард живёт в коде, а не в договорённостях.
 */
class PublishedPagesProtectedTest extends TestCase
{
    use RefreshDatabase;

    private string $wiki;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wiki = sys_get_temp_dir().'/xi-guard-'.uniqid();
        mkdir($this->wiki, 0777, true);
        Section::firstOrCreate(['slug' => 'wiki'], ['title' => 'Вики', 'position' => 1]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->wiki));
        parent::tearDown();
    }

    private function snapshotPage(string $title, string $body): void
    {
        file_put_contents($this->wiki.'/index.php@title='.md5($title),
            '<html><head><script>mw.config.set({"wgNamespaceNumber":0,'
            .'"wgTitle":"'.$title.'","wgAction":"view"});</script></head><body>'
            .'<h1 id="firstHeading" class="firstHeading">'.$title.'</h1>'
            .'<div id="mw-content-text"><p>'.$body.'</p></div>'
            .'<div class="printfooter">x</div></body></html>');
    }

    public function test_refresh_does_not_overwrite_published_page(): void
    {
        $this->snapshotPage('Курсы', str_repeat('текст из архива ', 10));
        $page = Page::create([
            'title' => 'Курсы',
            'slug' => 'kursy',
            'section_id' => Section::where('slug', 'wiki')->value('id'),
            'status' => 'published',
            'source_type' => 'archive_wiki',
            'body' => '<p>Вычитанный вручную текст</p>',
        ]);

        $this->artisan('import:offline-wiki', ['archive' => $this->wiki, '--refresh' => true])
            ->assertSuccessful();

        $this->assertSame('<p>Вычитанный вручную текст</p>', $page->fresh()->body);
    }

    public function test_refresh_updates_draft_page(): void
    {
        $this->snapshotPage('Белые', str_repeat('текст из архива ', 10));
        $page = Page::create([
            'title' => 'Белые',
            'slug' => 'belye',
            'section_id' => Section::where('slug', 'wiki')->value('id'),
            'status' => 'draft',
            'source_type' => 'archive_wiki',
            'body' => '<p>старое</p>',
        ]);

        $this->artisan('import:offline-wiki', ['archive' => $this->wiki, '--refresh' => true])
            ->assertSuccessful();

        $this->assertStringContainsString('текст из архива', $page->fresh()->body);
    }

    public function test_import_creates_missing_page_as_draft(): void
    {
        $this->snapshotPage('Сеанс с силами 20070730b', str_repeat('стенограмма ', 10));

        $this->artisan('import:offline-wiki', ['archive' => $this->wiki])->assertSuccessful();

        $page = Page::where('title', 'Сеанс с силами 20070730b')->first();
        $this->assertNotNull($page);
        $this->assertSame('draft', $page->status);
    }
}
