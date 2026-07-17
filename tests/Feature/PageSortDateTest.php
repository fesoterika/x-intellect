<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Дата материала (published_at) — та самая, по которой листинги разделов
 * сортируются «по дате». Правится в редакторе отдельным полем.
 */
class PageSortDateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_create_form_prefills_current_date_and_time(): void
    {
        Carbon::setTestNow('2026-07-17 14:30:00');

        $this->actingAs($this->admin())->get(route('admin.pages.create'))->assertOk()
            ->assertSee('Дата материала')
            ->assertSee('name="published_at" value="2026-07-17T14:30"', false);
    }

    public function test_date_persists_from_editor(): void
    {
        $base = ['title' => 'Тест', 'page_type' => 'page', 'status' => 'published', 'source_type' => 'new'];

        $this->actingAs($this->admin())
            ->post(route('admin.pages.store'), $base + ['slug' => 'test-date', 'published_at' => '2011-03-04T09:15']);

        $page = Page::where('slug', 'test-date')->first();
        $this->assertSame('2011-03-04 09:15:00', $page->published_at->format('Y-m-d H:i:s'));

        $this->actingAs($this->admin())
            ->put(route('admin.pages.update', $page), $base + ['slug' => 'test-date', 'published_at' => '1998-12-01T00:00']);

        $this->assertSame('1998-12-01 00:00:00', $page->fresh()->published_at->format('Y-m-d H:i:s'));
    }

    /** Пустое поле оставляет прежнее поведение: наблюдатель ставит дату публикации сам. */
    public function test_empty_date_falls_back_to_now_on_publish(): void
    {
        Carbon::setTestNow('2026-07-17 14:30:00');

        $this->actingAs($this->admin())->post(route('admin.pages.store'), [
            'title' => 'Тест', 'slug' => 'test-empty', 'page_type' => 'page',
            'status' => 'published', 'source_type' => 'new', 'published_at' => '',
        ]);

        $this->assertSame('2026-07-17 14:30:00', Page::where('slug', 'test-empty')->first()->published_at->format('Y-m-d H:i:s'));
    }

    public function test_listing_sorts_by_edited_date(): void
    {
        $section = Section::create(['title' => 'Статьи', 'slug' => 'articles', 'position' => 1]);

        foreach ([['a', '2010-01-01'], ['b', '2020-01-01']] as [$slug, $date]) {
            Page::create([
                'section_id' => $section->id,
                'title' => 'Страница '.$slug,
                'slug' => $slug,
                'body' => '<p>Текст.</p>',
                'status' => 'published',
                'published_at' => $date,
            ]);
        }

        $this->get('/articles?sort=new')->assertOk()->assertSeeInOrder(['Страница b', 'Страница a']);
        $this->get('/articles?sort=old')->assertOk()->assertSeeInOrder(['Страница a', 'Страница b']);

        // Правка даты из редактора меняет порядок листинга
        $a = Page::where('slug', 'a')->first();
        $this->actingAs($this->admin())->put(route('admin.pages.update', $a), [
            'title' => 'Страница a', 'slug' => 'a', 'page_type' => 'page',
            'status' => 'published', 'source_type' => 'new', 'section_id' => $section->id,
            'is_listed' => '1', 'published_at' => '2026-01-01T12:00',
        ]);

        $this->get('/articles?sort=new')->assertOk()->assertSeeInOrder(['Страница a', 'Страница b']);
    }
}
