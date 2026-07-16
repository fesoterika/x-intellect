<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_pages_search_finds_by_russian_title(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        Page::create(['section_id' => $section->id, 'title' => 'Проекты 2005 - 2012', 'slug' => 'proekty-2005-2012', 'body' => '<p>Что-то другое.</p>', 'status' => 'published']);
        Page::create(['section_id' => $section->id, 'title' => 'Другая страница', 'slug' => 'drugaya', 'body' => '<p>Тело без ключевого слова.</p>', 'status' => 'published']);

        // Регистронезависимо, кириллица (SQLite LOWER() её не сворачивает — regress)
        $this->actingAs($this->admin())->get(route('admin.pages.index', ['q' => 'проекты']))
            ->assertOk()
            ->assertSee('Проекты 2005 - 2012')
            ->assertDontSee('Другая страница');
    }

    public function test_pages_search_finds_by_body_and_digits(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        Page::create(['section_id' => $section->id, 'title' => 'Заголовок А', 'slug' => 'a', 'body' => '<p>Содержит слово ноосфера внутри тела.</p>', 'status' => 'published']);
        Page::create(['section_id' => $section->id, 'title' => 'Сеансы 2013', 'slug' => 'seansy-2013', 'body' => '<p>Пусто.</p>', 'status' => 'published']);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.pages.index', ['q' => 'НООСФЕРА']))
            ->assertOk()->assertSee('Заголовок А');
        $this->actingAs($admin)->get(route('admin.pages.index', ['q' => '2013']))
            ->assertOk()->assertSee('Сеансы 2013');
    }

    public function test_media_search_finds_by_russian_title(): void
    {
        Media::create(['type' => 'audio', 'title' => 'Запись Сеанса', 'file_path' => 'media/audio/x.mp3', 'disk' => 'public']);
        Media::create(['type' => 'audio', 'title' => 'Другое', 'file_path' => 'media/audio/y.mp3', 'disk' => 'public']);

        $this->actingAs($this->admin())->get(route('admin.media.index', ['q' => 'запись']))
            ->assertOk()
            ->assertSee('Запись Сеанса')
            ->assertDontSee('Другое');
    }

    public function test_redirects_search_finds_by_russian_comment(): void
    {
        Redirect::create(['from_path' => '/go/a.html', 'to_url' => 'https://a.example', 'status_code' => 301, 'comment' => 'Старая ссылка Дзена']);
        Redirect::create(['from_path' => '/go/b.html', 'to_url' => 'https://b.example', 'status_code' => 302, 'comment' => 'Прочее']);

        $this->actingAs($this->admin())->get(route('admin.redirects.index', ['q' => 'дзена']))
            ->assertOk()
            ->assertSee('/go/a.html')
            ->assertDontSee('/go/b.html');
    }
}
