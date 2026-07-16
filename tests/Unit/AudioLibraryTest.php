<?php

namespace Tests\Unit;

use App\Services\AudioLibrary;
use PHPUnit\Framework\TestCase;

class AudioLibraryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/xi-audio-'.uniqid();
        foreach (['slepok', 'papka', 'morozov'] as $dir) {
            mkdir($this->root.'/'.$dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    /**
     * Пишет mp3 из настоящей цепочки кадров MPEG2 Layer III (48 кбит/с, 22050 Гц):
     * длительность считается обходом кадров, поэтому одного заголовка мало.
     */
    private function mp3(string $path, float $minutes): void
    {
        $header = "\xFF\xF3\x60\xC4";
        $frameLength = intdiv((int) (576 / 8 * 48000), 22050);
        $frame = $header.str_repeat("\x00", $frameLength - 4);
        $frames = (int) round($minutes * 60 * 22050 / 576);
        file_put_contents($path, str_repeat($frame, max(1, $frames)));
    }

    public function test_ignores_html_saved_with_mp3_extension(): void
    {
        // Offline Explorer сохранял страницы описания файла как «…@title=X.mp3»
        file_put_contents($this->root.'/slepok/index.php@title=20070730b.mp3', '<!DOCTYPE html><html>…');
        $this->mp3($this->root.'/slepok/20070730b.mp3', 4.5);

        $lib = (new AudioLibrary([$this->root.'/slepok']))->build();

        $this->assertSame(1, $lib->count());
        $this->assertSame('20070730b.mp3', $lib->byDateKey('20070730b')[0]['name']);
    }

    public function test_prefers_the_longest_copy_over_folder_priority(): void
    {
        // В слепке запись обрезана, в папке аудио — полная: берём полную,
        // хотя слепок и приоритетнее (иначе теряем содержание).
        $this->mp3($this->root.'/slepok/20090607.mp3', 4.7); // обрезанная копия
        $this->mp3($this->root.'/papka/20090607.mp3', 5.7);  // полная запись

        $tracks = (new AudioLibrary([$this->root.'/slepok', $this->root.'/papka']))->byDateKey('20090607');

        $this->assertCount(1, $tracks);
        $this->assertSame('papka', $tracks[0]['source']);
    }

    public function test_folder_priority_breaks_tie_for_identical_copies(): void
    {
        $this->mp3($this->root.'/slepok/20100411.mp3', 2.9);
        $this->mp3($this->root.'/papka/20100411.mp3', 2.9);

        $tracks = (new AudioLibrary([$this->root.'/slepok', $this->root.'/papka']))->byDateKey('20100411');

        $this->assertCount(1, $tracks);
        $this->assertSame('slepok', $tracks[0]['source']);
    }

    public function test_same_recording_under_free_name_is_not_duplicated(): void
    {
        // «Архив Дмитрия Морозова» хранит те же записи с описательными именами
        $this->mp3($this->root.'/slepok/20101031.mp3', 7.6);
        $this->mp3($this->root.'/morozov/20101031 (Вопросы - Ответы).mp3', 7.6);

        $tracks = (new AudioLibrary([$this->root.'/slepok', $this->root.'/morozov']))->byDateKey('20101031');

        $this->assertCount(1, $tracks);
    }

    public function test_different_recordings_of_one_date_stay_separate(): void
    {
        $this->mp3($this->root.'/papka/20131123_nsf10_GOL.mp3', 3.2);
        $this->mp3($this->root.'/papka/20131123_nsf10_ZEL.mp3', 5.8);

        $tracks = (new AudioLibrary([$this->root.'/papka']))->byDateKey('20131123');

        $this->assertCount(2, $tracks);
    }

    /**
     * VBR без Xing: первый кадр заявляет высокий битрейт, дальше идут низкие.
     * Оценка «размер / битрейт первого кадра» здесь врёт в разы — так у слепка
     * 20090719 выходило «17 мин» вместо реальных 67, и полную запись едва не
     * заменили урезанной. Длительность обязана считаться обходом кадров.
     */
    public function test_duration_is_correct_for_vbr_without_xing(): void
    {
        $path = $this->root.'/slepok/vbr.mp3';
        // 100 кадров по 144 кбит/с, затем 900 по 48 кбит/с — все 22050 Гц
        $fast = "\xFF\xF3\xD0\xC4".str_repeat("\x00", intdiv((int) (576 / 8 * 144000), 22050) - 4);
        $slow = "\xFF\xF3\x60\xC4".str_repeat("\x00", intdiv((int) (576 / 8 * 48000), 22050) - 4);
        file_put_contents($path, str_repeat($fast, 100).str_repeat($slow, 900));

        $expected = 1000 * 576 / 22050 / 60;

        $this->assertEqualsWithDelta($expected, AudioLibrary::duration($path), 0.01);
    }

    public function test_date_key_of_title(): void
    {
        $this->assertSame('20120726a', AudioLibrary::dateKeyOf('Сеанс с силами 20120726a'));
        $this->assertSame('20130310', AudioLibrary::dateKeyOf('Сеанс с Силами 20130310'));
        $this->assertNull(AudioLibrary::dateKeyOf('Проект Биоэкран. Часть 1.'));
    }

    public function test_variant_of_filename(): void
    {
        $this->assertSame('', AudioLibrary::variantOf('20090607.mp3', '20090607'));
        $this->assertSame('', AudioLibrary::variantOf('20100411 (Вопросы - Ответы).mp3', '20100411'));
        $this->assertSame('nsf10gol', AudioLibrary::variantOf('20131123_nsf10_GOL.mp3', '20131123'));
    }
}
