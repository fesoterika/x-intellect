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

    /** Раздел с материалами только в подразделах («Проекты») выглядел пустым. */
    public function test_section_filter_includes_subsection_pages(): void
    {
        $root = Section::create(['title' => 'Статьи', 'slug' => 'articles']);
        $child = Section::create(['title' => 'Дайджесты', 'slug' => 'daidzesty', 'parent_id' => $root->id]);
        $other = Section::create(['title' => 'Курсы', 'slug' => 'kursy']);

        Page::create(['section_id' => $root->id, 'title' => 'Статья корня', 'slug' => 'a', 'status' => 'published']);
        Page::create(['section_id' => $child->id, 'title' => 'Статья дайджеста', 'slug' => 'b', 'status' => 'published']);
        Page::create(['section_id' => $other->id, 'title' => 'Статья курса', 'slug' => 'c', 'status' => 'published']);

        // Корень: свои материалы + материалы подразделов, чужие — нет
        $this->actingAs($this->admin())->get(route('admin.pages.index', ['section' => $root->id]))
            ->assertOk()
            ->assertSee('Статья корня')
            ->assertSee('Статья дайджеста')
            ->assertDontSee('Статья курса');
    }

    public function test_section_filter_by_subsection_shows_only_it(): void
    {
        $root = Section::create(['title' => 'Статьи', 'slug' => 'articles']);
        $child = Section::create(['title' => 'Дайджесты', 'slug' => 'daidzesty', 'parent_id' => $root->id]);

        Page::create(['section_id' => $root->id, 'title' => 'Статья корня', 'slug' => 'a', 'status' => 'published']);
        Page::create(['section_id' => $child->id, 'title' => 'Статья дайджеста', 'slug' => 'b', 'status' => 'published']);

        $this->actingAs($this->admin())->get(route('admin.pages.index', ['section' => $child->id]))
            ->assertOk()
            ->assertSee('Статья дайджеста')
            ->assertDontSee('Статья корня');
    }

    /** Подразделы выбираются в фильтре: раньше в списке были только корни. */
    public function test_section_filter_lists_subsections(): void
    {
        $root = Section::create(['title' => 'Статьи', 'slug' => 'articles']);
        Section::create(['title' => 'Дайджесты', 'slug' => 'daidzesty', 'parent_id' => $root->id]);

        $this->actingAs($this->admin())->get(route('admin.pages.index'))
            ->assertOk()
            ->assertSee('<optgroup label="Статьи">', false)
            ->assertSee('Дайджесты');
    }

    /** Фильтр раздела и поиск по слову работают вместе. */
    public function test_section_filter_combines_with_query(): void
    {
        $root = Section::create(['title' => 'Статьи', 'slug' => 'articles']);
        $child = Section::create(['title' => 'Дайджесты', 'slug' => 'daidzesty', 'parent_id' => $root->id]);

        Page::create(['section_id' => $child->id, 'title' => 'Ноосфера в дайджесте', 'slug' => 'a', 'status' => 'published']);
        Page::create(['section_id' => $child->id, 'title' => 'Прочее', 'slug' => 'b', 'status' => 'published']);

        $this->actingAs($this->admin())->get(route('admin.pages.index', ['section' => $root->id, 'q' => 'ноосфера']))
            ->assertOk()
            ->assertSee('Ноосфера в дайджесте')
            ->assertDontSee('Прочее');
    }

    /** Совпадения в заголовке — выше совпадений только в теле (при любой свежести). */
    public function test_pages_search_ranks_title_matches_first(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        // Совпадение в теле — самое свежее: без ранжирования шло бы первым
        Page::create(['section_id' => $section->id, 'title' => 'Про другое', 'slug' => 'a', 'body' => '<p>Слово ноосфера в теле.</p>', 'status' => 'published']);
        $byTitle = Page::create(['section_id' => $section->id, 'title' => 'Ноосфера планеты', 'slug' => 'b', 'body' => '<p>Пусто.</p>', 'status' => 'published']);
        Page::whereKey($byTitle->id)->update(['updated_at' => now()->subDay()]);

        $this->actingAs($this->admin())->get(route('admin.pages.index', ['q' => 'ноосфера']))
            ->assertOk()
            ->assertSeeInOrder(['Ноосфера планеты', 'Про другое']);
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

    /** Совпадения в названии — выше совпадений только по пути файла. */
    public function test_media_search_ranks_title_matches_first(): void
    {
        // Совпадение по пути — новее: без ранжирования latest() ставил бы его первым
        Media::create(['type' => 'audio', 'title' => 'Другое', 'file_path' => 'media/audio/seans-2013.mp3', 'disk' => 'public']);
        $byTitle = Media::create(['type' => 'audio', 'title' => 'Запись seans', 'file_path' => 'media/audio/y.mp3', 'disk' => 'public']);
        Media::whereKey($byTitle->id)->update(['created_at' => now()->subDay()]);

        $this->actingAs($this->admin())->get(route('admin.media.index', ['q' => 'seans']))
            ->assertOk()
            ->assertSeeInOrder(['Запись seans', 'Другое']);
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

    /** Совпадения в исходном пути — выше совпадений в комментарии. */
    public function test_redirects_search_ranks_from_path_matches_first(): void
    {
        // Совпадение в комментарии алфавитно раньше: без ранжирования шло бы первым
        Redirect::create(['from_path' => '/a.html', 'to_url' => 'https://a.example', 'status_code' => 301, 'comment' => 'Ссылка на дзен']);
        Redirect::create(['from_path' => '/go/dzen.html', 'to_url' => 'https://b.example', 'status_code' => 301, 'comment' => 'Прочее']);

        $this->actingAs($this->admin())->get(route('admin.redirects.index', ['q' => 'дзен']))
            ->assertOk()
            ->assertSeeInOrder(['/go/dzen.html', '/a.html']);
    }
}
