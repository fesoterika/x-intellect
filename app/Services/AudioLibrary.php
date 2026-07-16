<?php

namespace App\Services;

/**
 * Библиотека mp3 архива: индекс по трём источникам (офлайн-слепок, «Сайт и файлы
 * + аудио», «Архив Дмитрия Морозова»).
 *
 * Порядок папок задан пользователем как порядок ПОИСКА недостающего. Но одна и та
 * же запись лежит в нескольких папках, и копия из слепка местами обрезана: у
 * 20081111 в слепке 22 мин против 74 мин в «Сайт и файлы + аудио» (всего по
 * архиву так теряется ~98 минут на 4 записях). Цель — наиболее полный архив,
 * поэтому среди копий ОДНОЙ записи побеждает самая длинная, а приоритет папки —
 * лишь тай-брейк при равной длительности.
 *
 * Разные записи одной даты (например 20131123_nsf10_GOL и …_ZEL) различаются
 * «вариантом» — остатком имени файла после даты; они остаются отдельными
 * дорожками. Описательные скобки («20100411 (Вопросы - Ответы)») вариантом не
 * считаются: это тот же сеанс под вольным именем из архива Морозова.
 */
class AudioLibrary
{
    /** @var array<int, array{path:string,name:string,size:int,source:string,priority:int,duration:float,variant:string}> */
    private array $files = [];

    private bool $built = false;

    /**
     * @param  array<int, string>  $roots  Папки-источники в порядке приоритета.
     */
    public function __construct(private array $roots = []) {}

    public function withRoots(array $roots): self
    {
        $this->roots = $roots;
        $this->built = false;
        $this->files = [];

        return $this;
    }

    public function build(): self
    {
        if ($this->built) {
            return $this;
        }
        foreach ($this->roots as $priority => $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'mp3') {
                    continue;
                }
                $path = $file->getPathname();
                if (! $this->isRealMp3($path)) {
                    continue; // OE сохранял HTML-страницы описания файла как …@title=X.mp3
                }
                // Длительность считается лениво: обход кадров читает файл целиком,
                // а в библиотеке сотни записей на гигабайты.
                $this->files[] = [
                    'path' => $path,
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'source' => basename($root),
                    'priority' => $priority,
                    'variant' => '',
                ];
            }
        }
        $this->built = true;

        return $this;
    }

    public function count(): int
    {
        return count($this->build()->files);
    }

    /**
     * Дорожки по ключу-дате из заголовка (ГГГГММДД, возможно с буквой).
     * По одной записи на каждый «вариант» — самая полная копия.
     *
     * @return array<int, array{path:string,name:string,size:int,source:string,priority:int,duration:float,variant:string}>
     */
    public function byDateKey(string $key): array
    {
        $this->build();

        $groups = [];
        foreach ($this->files as $f) {
            if (! str_contains($f['name'], $key)) {
                continue;
            }
            $variant = self::variantOf($f['name'], $key);
            $f['variant'] = $variant;
            $best = $groups[$variant] ?? null;
            if ($best === null || $this->rank($f) < $this->rank($best)) {
                $groups[$variant] = $f;
            }
        }

        // Тот же файл под вольным именем без скобок («20101031 Астральные
        // перемещения….mp3») даёт ложный вариант: совпадение размера
        // байт-в-байт означает, что это одна и та же запись.
        $bySize = [];
        foreach ($groups as $f) {
            $best = $bySize[$f['size']] ?? null;
            if ($best === null || $this->rank($f) < $this->rank($best)) {
                $bySize[$f['size']] = $f;
            }
        }

        $out = array_map(fn ($f) => $this->withDuration($f), array_values($bySize));
        usort($out, fn ($a, $b) => [$a['variant'], $a['name']] <=> [$b['variant'], $b['name']]);

        return $out;
    }

    /** Файл по имени (для ссылок на чужой домен: …/files/audio/20070730b.mp3). */
    public function byName(string $name): ?array
    {
        $this->build();
        $hits = array_values(array_filter($this->files, fn ($f) => $f['name'] === $name));
        usort($hits, fn ($a, $b) => $this->rank($a) <=> $this->rank($b));

        return isset($hits[0]) ? $this->withDuration($hits[0]) : null;
    }

    /** Ключ-дата из заголовка: «Сеанс с силами 20120726a» → 20120726a. */
    public static function dateKeyOf(string $title): ?string
    {
        return preg_match('/((?:19|20)\d{6}[a-zA-Z]?)/u', $title, $m) ? $m[1] : null;
    }

    /** Остаток имени файла после даты: «20131123_nsf10_GOL» → «nsf10gol». */
    public static function variantOf(string $name, string $key): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/\([^)]*\)/u', '', $stem);   // описательные скобки
        $stem = str_replace($key, '', $stem);
        $stem = preg_replace('/\.mp3$/i', '', $stem);      // «20110508.mp3.mp3»
        $stem = mb_strtolower($stem);
        $stem = preg_replace('/[^a-zа-я0-9]+/u', '', $stem);

        return $stem ?? '';
    }

    /** Полнее — лучше; при равной длительности — приоритет папки. */
    private function rank(array $f): array
    {
        return [-round(self::duration($f['path'])), $f['priority'], $f['name']];
    }

    /** Дописывает длительность в записи о файлах (для вывода команд). */
    private function withDuration(array $f): array
    {
        $f['duration'] = self::duration($f['path']);

        return $f;
    }

    /** Похоже на настоящий mp3 (ID3 или синхросигнал кадра)? */
    private function isRealMp3(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 3);
        fclose($fh);
        if ($head === false || strlen($head) < 3) {
            return false;
        }

        return $head === 'ID3' || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0);
    }

    /** @var array<string, float> кеш длительностей по пути */
    private static array $durations = [];

    /**
     * Длительность в минутах — обходом всех кадров.
     *
     * Оценка «размер / битрейт первого кадра» тут не годится: часть архивных
     * записей — VBR без заголовка Xing, и первый кадр врёт в разы (у слепка
     * 20090719 первый кадр заявляет 144 кбит/с → «17 мин» вместо реальных 67).
     * На таких оценках команда заменила бы полную запись урезанной.
     */
    public static function duration(string $path): float
    {
        if (isset(self::$durations[$path])) {
            return self::$durations[$path];
        }

        // Читаем потоком: записи бывают в сотни мегабайт, целиком в память не лезут
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return self::$durations[$path] = 0.0;
        }

        $head = (string) fread($fh, 10);
        $offset = 0;
        if (strlen($head) === 10 && str_starts_with($head, 'ID3')) {
            // синхробезопасное 28-битное число: по 7 значащих бит на байт
            $offset = 10 + ((ord($head[6]) & 0x7F) << 21 | (ord($head[7]) & 0x7F) << 14
                | (ord($head[8]) & 0x7F) << 7 | (ord($head[9]) & 0x7F));
        }

        fseek($fh, $offset);
        $window = (string) fread($fh, 262144);
        $found = self::firstFrame($window, 0);
        if ($found === null) {
            fclose($fh);

            return self::$durations[$path] = 0.0;
        }

        $seconds = 0.0;
        $pos = $offset + $found;
        fseek($fh, $pos);
        while (true) {
            $bytes = fread($fh, 4);
            if ($bytes === false || strlen($bytes) < 4) {
                break;
            }
            $frame = self::frameAt($bytes, 0);
            if ($frame === null) {
                // мусор между кадрами (теги, склейки) — ищем следующий синхросигнал
                $pos++;
                fseek($fh, $pos);

                continue;
            }
            $seconds += $frame['spf'] / $frame['rate'];
            $pos += $frame['length'];
            fseek($fh, $pos);
        }
        fclose($fh);

        return self::$durations[$path] = $seconds / 60;
    }

    /** Первый кадр, за которым идёт цепочка из 12 кадров (отсев ложных синхросигналов). */
    private static function firstFrame(string $data, int $offset): ?int
    {
        $limit = min($offset + 200000, strlen($data) - 4);
        for ($i = $offset; $i < $limit; $i++) {
            if (self::frameAt($data, $i) === null) {
                continue;
            }
            $j = $i;
            $chain = 0;
            while ($chain < 12 && ($f = self::frameAt($data, $j)) !== null) {
                $j += $f['length'];
                $chain++;
            }
            if ($chain >= 12) {
                return $i;
            }
        }

        return null;
    }

    /** @return array{rate:int, spf:int, length:int}|null */
    private static function frameAt(string $data, int $i): ?array
    {
        if ($i + 4 > strlen($data) || ord($data[$i]) !== 0xFF || (ord($data[$i + 1]) & 0xE0) !== 0xE0) {
            return null;
        }
        $b1 = ord($data[$i + 1]);
        $b2 = ord($data[$i + 2]);
        $ver = ($b1 >> 3) & 3;      // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
        $layer = ($b1 >> 1) & 3;    // 1 = Layer III
        $bri = ($b2 >> 4) & 0xF;
        $sri = ($b2 >> 2) & 3;
        if ($ver === 1 || $layer !== 1 || $bri === 0 || $bri === 15 || $sri === 3) {
            return null;
        }

        $v1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
        $v2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
        $r1 = [44100, 48000, 32000];
        $r2 = [22050, 24000, 16000];
        $r25 = [11025, 12000, 8000];

        $bitrate = ($ver === 3 ? $v1 : $v2)[$bri] * 1000;
        $rate = match ($ver) {
            3 => $r1[$sri],
            2 => $r2[$sri],
            default => $r25[$sri],
        };
        $spf = $ver === 3 ? 1152 : 576;
        $padding = ($b2 >> 1) & 1;

        return [
            'rate' => $rate,
            'spf' => $spf,
            'length' => intdiv($spf / 8 * $bitrate, $rate) + $padding,
        ];
    }
}
