<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private Section $articles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 1]);
    }

    private function makePage(string $slug, string $status = 'published'): Page
    {
        return Page::create([
            'section_id' => $this->articles->id,
            'title' => 'Материал '.$slug,
            'slug' => $slug,
            'body' => '<p>Текст.</p>',
            'status' => $status,
        ]);
    }

    /** Страница переехала — редирект остался на прежнем адресе. */
    public function test_fix_repoints_redirect_to_actual_page_url(): void
    {
        $this->makePage('material');
        Redirect::create(['from_path' => '/staryi', 'to_url' => '/library/material', 'status_code' => 301]);

        $this->artisan('redirects:check --fix')->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from_path' => '/staryi', 'to_url' => '/articles/material']);
    }

    public function test_fix_collapses_chain_to_single_hop(): void
    {
        $this->makePage('konec');
        Redirect::create(['from_path' => '/a', 'to_url' => '/b', 'status_code' => 301]);
        Redirect::create(['from_path' => '/b', 'to_url' => '/articles/konec', 'status_code' => 301]);

        $this->artisan('redirects:check --fix')->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from_path' => '/a', 'to_url' => '/articles/konec']);
    }

    public function test_check_without_fix_changes_nothing(): void
    {
        $this->makePage('material');
        Redirect::create(['from_path' => '/staryi', 'to_url' => '/library/material', 'status_code' => 301]);

        $this->artisan('redirects:check')->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from_path' => '/staryi', 'to_url' => '/library/material']);
    }

    public function test_loop_is_reported_and_not_touched(): void
    {
        Redirect::create(['from_path' => '/a', 'to_url' => '/b', 'status_code' => 301]);
        Redirect::create(['from_path' => '/b', 'to_url' => '/a', 'status_code' => 301]);

        $this->artisan('redirects:check --fix')
            ->expectsOutputToContain('ПЕТЛЯ')
            ->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from_path' => '/a', 'to_url' => '/b']);
    }

    /** Редирект с адреса живой страницы делает её недоступной. */
    public function test_redirect_shadowing_live_page_is_reported(): void
    {
        $this->makePage('material');
        Redirect::create(['from_path' => '/articles/material', 'to_url' => '/articles/drugoe', 'status_code' => 301]);

        $this->artisan('redirects:check')
            ->expectsOutputToContain('перехватывает живую страницу')
            ->assertSuccessful();
    }

    /** Цель-черновик — не ошибка редиректа: пройдёт после публикации. */
    public function test_draft_target_is_reported_but_not_fixed(): void
    {
        $this->makePage('cernovik', 'draft');
        Redirect::create(['from_path' => '/staryi', 'to_url' => '/articles/cernovik', 'status_code' => 301]);

        $this->artisan('redirects:check --fix')
            ->expectsOutputToContain('черновик')
            ->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from_path' => '/staryi', 'to_url' => '/articles/cernovik']);
    }

    /** Цель без страницы вообще — угадывать нечего, только отчёт. */
    public function test_missing_target_is_reported_not_guessed(): void
    {
        Redirect::create(['from_path' => '/staryi', 'to_url' => '/articles/nikogda-ne-bylo', 'status_code' => 301]);

        $this->artisan('redirects:check --fix')
            ->expectsOutputToContain('Цели нет')
            ->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from_path' => '/staryi', 'to_url' => '/articles/nikogda-ne-bylo']);
    }
}
