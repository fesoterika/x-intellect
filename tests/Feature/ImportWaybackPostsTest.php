<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\WordPressArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Доимпорт записей основного сайта из Wayback Machine: записи 2014–2017,
 * которых нет в офлайн-слепке 2015 года.
 */
class ImportWaybackPostsTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'prognozy-na-2015-god';

    private const SNAP = '20160204174111';

    private string $tmp;

    private string $snapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/xi-wb-'.uniqid();
        $this->snapshot = $this->tmp.'/snapshot';
        mkdir($this->snapshot.'/wp-content/uploads/2013/01', 0777, true);
        file_put_contents($this->snapshot.'/wp-content/uploads/2013/01/pic.jpg', 'JPEGDATA');

        Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 1]);
        Storage::fake('public');

        // Кеш скачанного — во временный каталог, чтобы не трогать рабочий,
        // и без пауз: ждать веб-архив в тестах нечего.
        $archive = new WordPressArchive;
        $archive->cacheDir = $this->tmp.'/cache';
        $archive->sleepUs = 0;
        $archive->retrySleepS = 0;
        $this->app->instance(WordPressArchive::class, $archive);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
        parent::tearDown();
    }

    /** Снимок записи: в h2 заголовок покалечен темой, в <title> — целый. */
    private function postHtml(string $body = '<p>Тело записи достаточной длины для импорта.</p>'): string
    {
        return '<html><head><title>  «Прогнозы на 2015 год″ : X &#8212; ИНТЕЛЛЕКТ</title></head><body>'
            .'<div class="post" id="post-3017"><div class="title">'
            ."<div class='datebox'><span class='month'>Мар</span><span class='date'>01</span></div>"
            .'<h2><a href="http://www.x-intellect.org/'.self::SLUG.'/" rel="bookmark">«Прогнозы на 2015 год?</a></h2>'
            .'</div><div class="cover"><div class="entry">'.$body.'</div></div></div></body></html>';
    }

    /** Помесячный архив WordPress — единственный источник года. */
    private function archiveHtml(): string
    {
        return '<html><body>'
            ."<div class='datebox'><span class='month'>Мар</span><span class='date'>01</span></div>"
            .'<h2><a href="http://www.x-intellect.org/'.self::SLUG.'/">запись</a></h2>'
            .'</body></html>';
    }

    private function fakeArchive(?string $postHtml = null): void
    {
        $post = $postHtml ?? $this->postHtml();

        Http::fake(function (Request $request) use ($post) {
            $url = $request->url();

            if (str_contains($url, '/cdx/search/cdx')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $rows = [['original', 'timestamp']];
                if (($q['url'] ?? '') === 'x-intellect.org/'.self::SLUG.'/') {
                    // хост в CDX приходит с портом — путь всё равно должен совпасть
                    $rows[] = ['http://www.x-intellect.org:80/'.self::SLUG.'/', self::SNAP];
                }
                if (($q['url'] ?? '') === 'x-intellect.org/2015/03/') {
                    $rows[] = ['http://www.x-intellect.org:80/2015/03/', '20160205110943'];
                }

                return Http::response($rows);
            }

            return Http::response(str_contains($url, '/2015/03/') ? $this->archiveHtml() : $post);
        });
    }

    private function import(array $options = []): void
    {
        $this->artisan('import:wayback-posts', [
            '--only' => self::SLUG,
            '--snapshot' => $this->snapshot,
            '--dates-from' => 2015,
            '--dates-to' => 2015,
            ...$options,
        ])->assertSuccessful();
    }

    public function test_imports_post_as_listed_draft_with_wayback_source(): void
    {
        $this->fakeArchive();
        $this->import();

        $page = Page::where('slug', self::SLUG)->firstOrFail();

        $this->assertSame('draft', $page->status);
        $this->assertTrue((bool) $page->is_listed);
        $this->assertSame('archive_xintellect', $page->source_type);
        $this->assertSame(
            'https://web.archive.org/web/'.self::SNAP.'/http://www.x-intellect.org:80/'.self::SLUG.'/',
            $page->source_url,
        );
        $this->assertSame('2016-02-04', $page->archived_at->format('Y-m-d'));
        $this->assertNotEmpty($page->excerpt);
    }

    /**
     * Заголовок берётся из <title>: в тексте ссылки старый сайт калечил
     * символы, и в базу уезжало «Прогнозы на 2015 год?».
     */
    public function test_title_comes_from_title_tag_not_from_mangled_heading(): void
    {
        $this->fakeArchive();
        $this->import();

        $this->assertSame('«Прогнозы на 2015 год″', Page::where('slug', self::SLUG)->value('title'));
    }

    /** Год есть только в помесячном архиве — плашка записи его не содержит. */
    public function test_publish_date_comes_from_monthly_archive(): void
    {
        $this->fakeArchive();
        $this->import();

        $this->assertSame('2015-03-01', Page::where('slug', self::SLUG)->value('published_at')->format('Y-m-d'));
    }

    public function test_writes_301_from_old_flat_address(): void
    {
        $this->fakeArchive();
        $this->import();

        $redirect = Redirect::where('from_path', '/'.self::SLUG)->firstOrFail();

        $this->assertSame(Page::where('slug', self::SLUG)->first()->url(), $redirect->to_url);
        $this->assertSame(301, $redirect->status_code);
    }

    /** Картинка из слепка переносится, а не заснятая веб-архивом — выбрасывается. */
    public function test_snapshot_images_are_copied_and_missing_ones_dropped(): void
    {
        $this->fakeArchive($this->postHtml(
            '<p>Тело записи достаточной длины для импорта.</p>'
            .'<p><img src="http://www.x-intellect.org/wp-content/uploads/2013/01/pic.jpg" alt="Есть в слепке" /></p>'
            .'<p><img src="http://www.x-intellect.org/wp-content/uploads/2015/03/net.jpg" alt="Нет нигде" /></p>',
        ));
        $this->import();

        $body = Page::where('slug', self::SLUG)->value('body');

        $this->assertStringContainsString('/storage/media/archive/', $body);
        $this->assertStringNotContainsString('net.jpg', $body);
        $this->assertStringNotContainsString('x-intellect.org/wp-content', $body);
        $this->assertCount(1, Storage::disk('public')->files('media/archive'));
    }

    public function test_second_run_creates_no_duplicate(): void
    {
        $this->fakeArchive();
        $this->import();
        $this->import();

        $this->assertSame(1, Page::where('source_url', 'like', '%/'.self::SLUG.'/')->count());
    }

    /**
     * Сбой CDX нельзя принимать за «снимков нет»: веб-архив регулярно отвечает
     * 429/503, и молчаливый пропуск потерял бы живой материал.
     */
    public function test_cdx_failure_is_reported_and_nothing_is_imported(): void
    {
        Http::fake(function (Request $request) {
            return str_contains($request->url(), '/cdx/search/cdx')
                ? Http::response('rate limited', 429)
                : Http::response($this->postHtml());
        });

        $this->artisan('import:wayback-posts', [
            '--only' => self::SLUG,
            '--snapshot' => $this->snapshot,
            '--dates-from' => 2015,
            '--dates-to' => 2015,
        ])->assertFailed();

        $this->assertSame(0, Page::where('slug', self::SLUG)->count());
    }

    /** Уже перенесённое (в том числе вычитанное и опубликованное) не трогаем. */
    public function test_existing_published_page_is_not_touched(): void
    {
        $page = Page::create([
            'section_id' => Section::where('slug', 'articles')->value('id'),
            'title' => 'Вычитанный вручную заголовок',
            'slug' => self::SLUG,
            'body' => '<p>Вычитанное тело.</p>',
            'status' => 'published',
            'source_type' => 'archive_xintellect',
            'source_url' => 'https://web.archive.org/web/2015/http://www.x-intellect.org/'.self::SLUG.'/',
        ]);

        $this->fakeArchive();
        $this->import();

        $page->refresh();
        $this->assertSame('Вычитанный вручную заголовок', $page->title);
        $this->assertSame('published', $page->status);
        $this->assertSame(1, Page::where('slug', 'like', self::SLUG.'%')->count());
    }
}
