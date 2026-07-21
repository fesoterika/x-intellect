<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Обложка плеера для Media Session API (экран блокировки/шторка iOS и
 * Android): приоритет og-картинка страницы → первая картинка в тексте →
 * стандартная OG-заглушка сайта. Плеер передаёт её в Alpine-компонент
 * третьим аргументом x-data вместе с заголовком страницы (артист трека).
 */
class PlayerCoverImageTest extends TestCase
{
    use RefreshDatabase;

    private Section $wiki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wiki = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
    }

    private function makePage(string $slug, array $attrs = []): Page
    {
        return Page::create($attrs + [
            'section_id' => $this->wiki->id,
            'title' => 'Сеанс с Силами 20130721',
            'slug' => $slug,
            'body' => '<p>Текст.</p>',
            'status' => 'published',
        ]);
    }

    public function test_falls_back_to_default_og_image_without_pictures(): void
    {
        $page = $this->makePage('bez-kartinok');

        $this->assertSame(
            rtrim(config('app.url'), '/').'/images/x-intellect_logo.webp',
            $page->coverImageUrl(),
        );
    }

    public function test_uses_first_body_image_when_no_og_image_set(): void
    {
        // PageObserver перегенерирует body_rendered из body на каждое
        // сохранение (см. saving()) — обложку нужно готовить через body,
        // а не подсовывать body_rendered напрямую.
        $page = $this->makePage('s-kartinkoi', [
            'body' => '<p>Текст</p><img src="/storage/media/archive/photo.png"><img src="/storage/media/archive/second.png">',
        ]);

        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/media/archive/photo.png',
            $page->coverImageUrl(),
        );
    }

    public function test_manual_og_image_wins_over_body_image(): void
    {
        $page = $this->makePage('s-og-kartinkoi', [
            'body' => '<img src="/storage/media/archive/photo.png">',
            'seo' => ['og_image' => 'https://example.org/cover.jpg'],
        ]);

        $this->assertSame('https://example.org/cover.jpg', $page->coverImageUrl());
    }

    public function test_relative_og_image_is_absolutized(): void
    {
        $page = $this->makePage('s-otnositelnym-og', [
            'seo' => ['og_image' => '/storage/media/archive/cover.png'],
        ]);

        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/media/archive/cover.png',
            $page->coverImageUrl(),
        );
    }

    public function test_audio_player_passes_cover_and_page_title_to_alpine(): void
    {
        $page = $this->makePage('s-audio', [
            'body' => '<img src="/storage/media/archive/photo.png">',
        ]);
        Media::create([
            'page_id' => $page->id,
            'type' => 'audio',
            'title' => 'Часть 1',
            'file_path' => 'media/audio/track.mp3',
            'disk' => 'public',
            'mime' => 'audio/mpeg',
        ]);

        $expectedCover = $page->fresh()->coverImageUrl();
        $this->assertStringContainsString('/storage/media/archive/photo.png', $expectedCover);

        // Js::from() отдаёт JSON со слешами, экранированными под JS-строку
        // (Blade x-data="audioPlayer(..., '{{ Js::from($cover) }}', ...)")
        $expectedCoverInScript = str_replace('/', '\/', $expectedCover);

        $this->get($page->url())
            ->assertOk()
            ->assertSee('audioPlayer(', false)
            ->assertSee($expectedCoverInScript, false)
            ->assertSee($page->title, false);
    }
}
