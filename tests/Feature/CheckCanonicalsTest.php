<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckCanonicalsTest extends TestCase
{
    use RefreshDatabase;

    private Section $articles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 1]);
    }

    private function makePage(array $attributes = []): Page
    {
        return Page::create(array_merge([
            'section_id' => $this->articles->id,
            'title' => 'Материал',
            'slug' => 'material',
            'body' => '<p>Текст.</p>',
            'status' => 'published',
        ], $attributes));
    }

    /** Canonical, отставший от переезда, спорит с собственным 301. */
    public function test_fix_repoints_stale_canonical_to_actual_url(): void
    {
        $page = $this->makePage();
        $page->forceFill(['seo' => ['canonical' => config('app.url').'/library/material']])->saveQuietly();

        $this->artisan('seo:canonical --fix')->assertSuccessful();

        $this->assertSame(config('app.url').'/articles/material', $page->fresh()->seoValue('canonical'));
    }

    public function test_check_without_fix_changes_nothing(): void
    {
        $page = $this->makePage();
        $page->forceFill(['seo' => ['canonical' => config('app.url').'/library/material']])->saveQuietly();

        $this->artisan('seo:canonical')->assertSuccessful();

        $this->assertSame(config('app.url').'/library/material', $page->fresh()->seoValue('canonical'));
    }

    /** Чужой хост — осознанный выбор редактора. */
    public function test_foreign_host_canonical_is_left_alone(): void
    {
        $page = $this->makePage();
        $page->forceFill(['seo' => ['canonical' => 'https://example.org/pervoistocnik']])->saveQuietly();

        $this->artisan('seo:canonical --fix')->assertSuccessful();

        $this->assertSame('https://example.org/pervoistocnik', $page->fresh()->seoValue('canonical'));
    }

    public function test_correct_canonical_is_reported_as_valid(): void
    {
        $this->makePage();

        $this->artisan('seo:canonical')
            ->expectsOutputToContain('0 устаревших')
            ->assertSuccessful();
    }
}
