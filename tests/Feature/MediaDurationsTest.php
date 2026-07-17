<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Section;
use App\Services\AudioLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Длительности аудио: разбор WAV-заголовка и заполнение media.duration
 * (в секундах — их форматирует durationLabel()).
 */
class MediaDurationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Настоящий минимальный WAV: PCM 8 кГц / 8 бит / моно, $seconds секунд.
     * Между fmt и data вставлен чанк LIST — как в реальных файлах, где
     * разбор по фиксированному смещению 44 промахивается.
     */
    private function makeWav(int $seconds): string
    {
        $byteRate = 8000;
        $data = str_repeat("\x80", $byteRate * $seconds);
        $fmt = pack('vvVVvv', 1, 1, 8000, $byteRate, 1, 8);
        $list = 'INFO';

        $chunks = 'fmt '.pack('V', strlen($fmt)).$fmt
            .'LIST'.pack('V', strlen($list)).$list
            .'data'.pack('V', strlen($data)).$data;

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WAVE'.$chunks;
    }

    public function test_wav_duration_is_parsed_from_riff_header(): void
    {
        $file = sys_get_temp_dir().'/xi-dur-'.uniqid().'.wav';
        file_put_contents($file, $this->makeWav(90));

        $minutes = AudioLibrary::duration($file);
        unlink($file);

        $this->assertEqualsWithDelta(1.5, $minutes, 0.01);
    }

    public function test_command_fills_durations_in_seconds(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/audio/archive/test.wav', $this->makeWav(75));

        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'position' => 1]);
        $page = Page::create([
            'section_id' => $section->id, 'title' => 'Сеанс', 'slug' => 'seans',
            'body' => '<p>Тело.</p>', 'status' => 'draft', 'source_type' => 'archive_wiki',
        ]);
        $media = Media::create([
            'page_id' => $page->id, 'type' => 'audio', 'title' => 'Запись',
            'file_path' => 'media/audio/archive/test.wav', 'disk' => 'public', 'mime' => 'audio/wav',
        ]);

        $this->artisan('media:durations')->assertSuccessful();

        $media->refresh();
        $this->assertSame(75, $media->duration);
        $this->assertSame('01:15', $media->durationLabel());
    }

    public function test_command_skips_already_filled_without_force(): void
    {
        Storage::fake('public');
        // Имя файла своё: AudioLibrary кеширует длительность по пути,
        // а фейковый диск между тестами живёт по одному и тому же корню
        Storage::disk('public')->put('media/audio/archive/test-force.wav', $this->makeWav(30));

        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'position' => 1]);
        $page = Page::create([
            'section_id' => $section->id, 'title' => 'Сеанс', 'slug' => 'seans',
            'body' => '<p>Тело.</p>', 'status' => 'draft', 'source_type' => 'archive_wiki',
        ]);
        $media = Media::create([
            'page_id' => $page->id, 'type' => 'audio', 'title' => 'Запись',
            'file_path' => 'media/audio/archive/test-force.wav', 'disk' => 'public',
            'mime' => 'audio/wav', 'duration' => 999,
        ]);

        $this->artisan('media:durations')->assertSuccessful();
        $this->assertSame(999, $media->fresh()->duration);

        $this->artisan('media:durations', ['--force' => true])->assertSuccessful();
        $this->assertSame(30, $media->fresh()->duration);
    }
}
