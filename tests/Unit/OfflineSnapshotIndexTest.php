<?php

namespace Tests\Unit;

use App\Services\OfflineSnapshotIndex;
use PHPUnit\Framework\TestCase;

class OfflineSnapshotIndexTest extends TestCase
{
    private string $wiki;

    private OfflineSnapshotIndex $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wiki = sys_get_temp_dir().'/xi-wiki-'.uniqid();
        mkdir($this->wiki.'/%&Ovr3', 0777, true);
        $this->index = new OfflineSnapshotIndex;
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->wiki));
        parent::tearDown();
    }

    private function page(string $title, string $body, string $extraHead = '', int $ns = 0): string
    {
        return '<html><head><script>mw.config.set({"wgNamespaceNumber":'.$ns.','
            .'"wgTitle":"'.$title.'","wgAction":"view"});</script></head><body>'
            .$extraHead
            .'<div id="mw-content-text">'.$body.'</div><div class="printfooter">x</div>'
            .'</body></html>';
    }

    private function write(string $relPath, string $html): void
    {
        file_put_contents($this->wiki.'/'.$relPath, $html);
    }

    /** Главная причина недостачи: OE ссыпает часть страниц в %&OvrN. */
    public function test_finds_pages_inside_ovr_subfolders(): void
    {
        $this->write('%&Ovr3/index.php@title=_25D0_25A1_25D0A04AB161',
            $this->page('Сеанс с силами 20070730b', str_repeat('стенограмма ', 20)));

        $pages = $this->index->build($this->wiki);

        $this->assertArrayHasKey('сеанс с силами 20070730b', $pages);
    }

    /** Имена файлов OE обрезает, поэтому вид страницы определяем по разметке. */
    public function test_skips_diff_and_redirect_views(): void
    {
        $this->write('index.php@title=Diff', $this->page('Творческие группы',
            '<table class=\'diff diff-contentalign-left\'><tr><td>Строка 1:</td></tr></table>'.str_repeat('x ', 30)));
        $this->write('index.php@title=Redir', $this->page('Финансовая поддержка',
            '<div class="redirectMsg">перенаправление Поддержка</div>'.str_repeat('y ', 30)));

        $pages = $this->index->build($this->wiki);

        $this->assertArrayNotHasKey('творческие группы', $pages);
        $this->assertArrayNotHasKey('финансовая поддержка', $pages);
    }

    public function test_prefers_canonical_view_over_old_revision(): void
    {
        // Старая ревизия длиннее из-за баннера, но канонический просмотр важнее
        $this->write('index.php@title=Old', $this->page('Белые',
            str_repeat('старый текст ', 40), '<div id="mw-revision-info">Версия от…</div>'));
        $this->write('%&Ovr3/index.php@title=Cur', $this->page('Белые', str_repeat('текст ', 20)));

        $pages = $this->index->build($this->wiki);

        $this->assertSame(OfflineSnapshotIndex::CANONICAL, $pages['белые']['kind']);
        $this->assertStringContainsString('Ovr3', $pages['белые']['path']);
        $this->assertSame(2, $pages['белые']['variants']);
    }

    public function test_skips_stubs_and_non_articles(): void
    {
        $this->write('index.php@title=Stub', $this->page('Пустая',
            'В настоящее время на этой странице нет текста. Вы можете найти упоминание…'));
        $this->write('index.php@title=Service', $this->page('Служебная:Поиск', str_repeat('z ', 40), '', 4));

        $pages = $this->index->build($this->wiki);

        $this->assertArrayNotHasKey('пустая', $pages);
        $this->assertArrayNotHasKey('служебная:поиск', $pages);
    }

    public function test_collects_mp3_links_from_all_variants(): void
    {
        $this->write('index.php@title=A', $this->page('Сеанс',
            '<a href="../files/audio/a.mp3">A</a>'.str_repeat('t ', 30)));
        $this->write('%&Ovr3/index.php@title=B', $this->page('Сеанс',
            '<a href="../files/audio/b.mp3">B</a>'.str_repeat('t ', 40)));

        $pages = $this->index->build($this->wiki);

        $this->assertCount(2, $pages['сеанс']['mp3']);
    }

    /** Ссылки указывают на файл в чужой папке — карта имён его находит. */
    public function test_file_map_indexes_all_folders_by_name(): void
    {
        $this->write('index.php@title=Root', $this->page('Курсы', str_repeat('k ', 30)));
        $this->write('%&Ovr3/index.php@title=Plan_A04AB161', $this->page('План тренинга', str_repeat('p ', 30)));

        $map = $this->index->fileMap($this->wiki);

        $this->assertArrayHasKey('index.php@title=Plan_A04AB161', $map);
        $this->assertStringContainsString('%&Ovr3', $map['index.php@title=Plan_A04AB161']);
    }

    public function test_normalize_folds_case_and_yo(): void
    {
        $this->assertSame('черные', $this->index->normalize('Чёрные'));
        $this->assertSame('курсы', $this->index->normalize('  Курсы '));
    }
}
