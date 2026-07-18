<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Смена корневого раздела меняет адрес страницы (Page::url() идёт через
 * rootAncestor). Без 301 старый адрес молча превращается в 404.
 */
class PageMoveRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Section $articles;
    private Section $projects;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 1]);
        $this->projects = Section::firstOrCreate(['slug' => 'projects'], ['title' => 'Проекты', 'position' => 2]);
    }

    private function makePage(?Section $section = null, string $slug = 'material'): Page
    {
        return Page::create([
            'section_id' => ($section ?? $this->articles)->id,
            'title' => 'Материал',
            'slug' => $slug,
            'body' => '<p>Текст.</p>',
            'status' => 'published',
        ]);
    }

    public function test_moving_to_another_root_section_creates_301(): void
    {
        $page = $this->makePage();

        $page->update(['section_id' => $this->projects->id]);

        $this->assertSame('/projects/material', $page->fresh()->url());
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/articles/material',
            'to_url' => '/projects/material',
            'status_code' => 301,
        ]);
    }

    public function test_changing_slug_creates_301(): void
    {
        $page = $this->makePage();

        $page->update(['slug' => 'novyi-slug']);

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/articles/material',
            'to_url' => '/articles/novyi-slug',
        ]);
    }

    /** Подраздел живёт под адресом корня — адрес не меняется, редирект не нужен. */
    public function test_moving_between_subsections_of_same_root_creates_nothing(): void
    {
        $child = Section::create(['parent_id' => $this->articles->id, 'title' => 'Дайджесты', 'slug' => 'daidzesty', 'position' => 1]);
        $page = $this->makePage();

        $page->update(['section_id' => $child->id]);

        $this->assertSame('/articles/material', $page->fresh()->url());
        $this->assertSame(0, Redirect::count());
    }

    public function test_creating_page_creates_no_redirect(): void
    {
        $this->makePage();

        $this->assertSame(0, Redirect::count());
    }

    /** Иначе на каждом переезде копится лишний хоп. */
    public function test_incoming_redirects_are_repointed_to_new_url(): void
    {
        Redirect::create(['from_path' => '/staryi-arxivnyi-adres', 'to_url' => '/articles/material', 'status_code' => 301]);
        $page = $this->makePage();

        $page->update(['section_id' => $this->projects->id]);

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/staryi-arxivnyi-adres',
            'to_url' => '/projects/material',
        ]);
    }

    /**
     * Возврат страницы на прежний адрес: встречный редирект обязан исчезнуть,
     * иначе middleware (он работает ДО маршрутизации) уводит с живого адреса
     * и переходы зацикливаются.
     */
    public function test_moving_back_removes_the_opposing_redirect(): void
    {
        $page = $this->makePage();

        $page->update(['section_id' => $this->projects->id]);
        $page->update(['section_id' => $this->articles->id]);

        $this->assertDatabaseMissing('redirects', ['from_path' => '/articles/material']);
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/projects/material',
            'to_url' => '/articles/material',
        ]);

        // страница снова открывается по своему адресу, а не улетает по 301
        $this->assertSame('/articles/material', $page->fresh()->url());
        $this->get('/articles/material')->assertOk();
    }

    /** Canonical не запекается в seo — шаблон строит его от текущего адреса. */
    public function test_canonical_is_not_stored_and_follows_the_page(): void
    {
        $page = $this->makePage();
        $this->assertNull($page->seoValue('canonical'));

        $page->update(['section_id' => $this->projects->id]);

        $this->assertNull($page->fresh()->seoValue('canonical'));
        $this->get('/projects/material')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.config('app.url').'/projects/material">', false);
    }

    /** Наследие автозаполнения (или seo:canonical --fix): запечённый под старый адрес canonical снимается. */
    public function test_stored_auto_pattern_canonical_is_cleared_on_move(): void
    {
        $page = $this->makePage();
        $page->forceFill(['seo' => ['canonical' => config('app.url').'/articles/material']])->saveQuietly();

        $page->update(['section_id' => $this->projects->id]);

        $this->assertNull($page->fresh()->seoValue('canonical'));
    }

    public function test_manual_canonical_is_left_alone(): void
    {
        $page = $this->makePage();
        $page->update(['seo' => ['canonical' => 'https://example.org/svoi-adres']]);

        $page->update(['section_id' => $this->projects->id]);

        $this->assertSame('https://example.org/svoi-adres', $page->fresh()->seoValue('canonical'));
    }
}
