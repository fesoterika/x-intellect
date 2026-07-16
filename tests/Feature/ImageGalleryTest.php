<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Services\ImageGallery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Идущие подряд картинки (ряды миниатюр вики) собираются в .xi-gallery —
 * только в рендере; сырое тело для Trix остаётся прежним.
 */
class ImageGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function figure(string $name, string $class = 'attachment attachment--preview'): string
    {
        return '<figure class="'.$class.'"><img src="/storage/media/archive/'.$name.'.gif" alt="'.$name.'"></figure>';
    }

    public function test_consecutive_figures_are_wrapped_into_a_row(): void
    {
        $html = (new ImageGallery)->process(
            '<div><strong>Шамбала</strong>'.$this->figure('SH1').$this->figure('SH7').'</div>'
        );

        $this->assertStringContainsString('<div class="xi-gallery">', $html);
        $this->assertSame(1, substr_count($html, 'xi-gallery'));
        // обе картинки внутри одной обёртки, порядок сохранён
        $this->assertMatchesRegularExpression(
            '~<div class="xi-gallery">\s*<figure[^>]*>.*?SH1\.gif.*?</figure>\s*<figure[^>]*>.*?SH7\.gif.*?</figure>\s*</div>~s',
            $html,
        );
    }

    public function test_single_figure_and_text_between_images_are_left_alone(): void
    {
        $lonely = '<p>т</p>'.$this->figure('SH555');
        $this->assertStringNotContainsString('xi-gallery', (string) (new ImageGallery)->process($lonely));

        $separated = $this->figure('SH1').'<p>текст между картинками</p>'.$this->figure('SH7');
        $this->assertStringNotContainsString('xi-gallery', (string) (new ImageGallery)->process($separated));
    }

    public function test_floated_figures_keep_their_own_layout(): void
    {
        $html = (new ImageGallery)->process(
            $this->figure('KN', 'attachment attachment--preview xi-float-right')
            .$this->figure('KN2', 'attachment attachment--preview xi-float-right')
        );

        $this->assertStringNotContainsString('xi-gallery', (string) $html);
    }

    public function test_gallery_applies_to_render_but_not_to_raw_body(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
        $body = '<div>'.$this->figure('SH1').$this->figure('SH7').'</div>';

        $page = Page::create([
            'section_id' => $section->id,
            'title' => 'Картины',
            'body' => $body,
            'status' => 'draft',
            'source_type' => 'archive_wiki',
        ]);

        $this->assertStringNotContainsString('xi-gallery', $page->body);
        $this->assertStringContainsString('xi-gallery', (string) $page->body_rendered);
    }

    /**
     * Галерея не должна разрывать пару «картинка + таблица»: TableImagePairer
     * ищет фигуру соседом таблицы, обёртка бы это соседство убила.
     */
    public function test_image_table_pair_survives(): void
    {
        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);

        $page = Page::create([
            'section_id' => $section->id,
            'title' => 'Картины с карточкой',
            'body' => '<div>'.$this->figure('KN', 'attachment attachment--preview xi-float-right')
                .'<table><tbody><tr><td>Проект</td></tr></tbody></table></div>',
            'status' => 'draft',
            'source_type' => 'archive_wiki',
        ]);

        $this->assertStringContainsString('xi-imgtable--right', (string) $page->body_rendered);
    }
}
