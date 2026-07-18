<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Services\AudioLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Длительность аудио заполняется сама при каждой загрузке (MediaObserver):
 * из админ-формы «Медиа», из Trix-редактора и при любом создании Media
 * с файлом на диске. Ручное значение не перетирается; замена файла
 * пересчитывает. Плюс разбор M4A (атом mvhd) в AudioLibrary.
 */
class MediaAutoDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** Настоящий минимальный WAV (PCM 8 кГц / 8 бит / моно, $seconds секунд). */
    private function makeWav(int $seconds): string
    {
        $byteRate = 8000;
        $data = str_repeat("\x80", $byteRate * $seconds);
        $fmt = pack('vvVVvv', 1, 1, 8000, $byteRate, 1, 8);

        $chunks = 'fmt '.pack('V', strlen($fmt)).$fmt
            .'data'.pack('V', strlen($data)).$data;

        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WAVE'.$chunks;
    }

    /** Минимальный M4A: атомы ftyp + moov/mvhd (v0, timescale 1000). */
    private function makeM4a(int $seconds): string
    {
        $mvhdBody = "\x00\x00\x00\x00" // version + flags
            .pack('N', 0).pack('N', 0) // creation, modification
            .pack('N', 1000)           // timescale
            .pack('N', $seconds * 1000); // duration
        $mvhd = pack('N', 8 + strlen($mvhdBody)).'mvhd'.$mvhdBody;
        $moov = pack('N', 8 + strlen($mvhd)).'moov'.$mvhd;

        return pack('N', 16).'ftyp'.'M4A '.pack('N', 0).$moov;
    }

    private function makePage(): Page
    {
        $section = Section::firstOrCreate(['slug' => 'wiki'], ['title' => 'Вики', 'position' => 1]);

        return Page::create([
            'section_id' => $section->id, 'title' => 'Сеанс', 'slug' => 'seans',
            'body' => '<p>Тело.</p>', 'status' => 'draft', 'source_type' => 'archive_wiki',
        ]);
    }

    public function test_m4a_duration_is_parsed_from_mvhd_atom(): void
    {
        $file = sys_get_temp_dir().'/xi-m4a-'.uniqid().'.m4a';
        file_put_contents($file, $this->makeM4a(90));

        $minutes = AudioLibrary::duration($file);
        unlink($file);

        $this->assertEqualsWithDelta(1.5, $minutes, 0.01);
    }

    public function test_duration_is_filled_on_media_create(): void
    {
        Storage::disk('public')->put('media/audio/auto-create.wav', $this->makeWav(75));

        $media = Media::create([
            'page_id' => $this->makePage()->id, 'type' => 'audio', 'title' => 'Запись',
            'file_path' => 'media/audio/auto-create.wav', 'disk' => 'public', 'mime' => 'audio/wav',
        ]);

        $this->assertSame(75, $media->fresh()->duration);
    }

    public function test_manual_duration_is_kept_on_create(): void
    {
        Storage::disk('public')->put('media/audio/auto-manual.wav', $this->makeWav(30));

        $media = Media::create([
            'page_id' => $this->makePage()->id, 'type' => 'audio', 'title' => 'Запись',
            'file_path' => 'media/audio/auto-manual.wav', 'disk' => 'public',
            'mime' => 'audio/wav', 'duration' => 999,
        ]);

        $this->assertSame(999, $media->fresh()->duration);
    }

    public function test_duration_is_recomputed_when_file_is_replaced(): void
    {
        Storage::disk('public')->put('media/audio/auto-old.wav', $this->makeWav(30));
        Storage::disk('public')->put('media/audio/auto-new.wav', $this->makeWav(120));

        $media = Media::create([
            'page_id' => $this->makePage()->id, 'type' => 'audio', 'title' => 'Запись',
            'file_path' => 'media/audio/auto-old.wav', 'disk' => 'public', 'mime' => 'audio/wav',
        ]);
        $this->assertSame(30, $media->fresh()->duration);

        $media->fresh()->update(['file_path' => 'media/audio/auto-new.wav']);

        $this->assertSame(120, $media->fresh()->duration);
    }

    public function test_editor_upload_fills_duration(): void
    {
        $editor = User::factory()->create();
        $editor->forceFill(['role' => 'editor'])->save();

        $upload = UploadedFile::fake()->createWithContent('zapis.wav', $this->makeWav(45));

        $response = $this->actingAs($editor)->post(route('admin.editor.upload'), [
            'file' => $upload,
            'page_id' => $this->makePage()->id,
        ]);

        $response->assertOk()->assertJsonPath('type', 'audio');
        $this->assertSame(45, Media::find($response->json('id'))->duration);
    }

    public function test_admin_media_form_upload_fills_duration(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $upload = UploadedFile::fake()->createWithContent('zapis.wav', $this->makeWav(60));

        $this->actingAs($admin)->post(route('admin.media.store'), [
            'title' => 'Новая запись',
            'type' => 'audio',
            'file' => $upload,
        ])->assertRedirect();

        $this->assertSame(60, Media::where('title', 'Новая запись')->first()->duration);
    }
}
