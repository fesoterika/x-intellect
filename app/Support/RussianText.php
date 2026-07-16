<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Кириллица в SQL-запросах. Прод (MySQL, utf8mb4_unicode_ci) сортирует и
 * сравнивает русский текст сам; локальный SQLite сравнивает байтами и не
 * сворачивает регистр кириллицы, поэтому на его соединении регистрируются
 * PHP-коллация xi_ru (сортировка по русскому алфавиту) и функция
 * xi_lower() (регистронезависимый LIKE) — тот же приём, что в поиске по сайту.
 */
class RussianText
{
    /** ORDER BY по текстовой колонке с учётом русского алфавита. */
    public static function titleOrder(string $column, string $direction = 'asc'): string
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if (self::isSqlite()) {
            self::registerCollation();

            return "{$column} collate xi_ru {$direction}";
        }

        return "{$column} {$direction}";
    }

    /**
     * Условие «колонка содержит подстроку» без учёта регистра
     * (спецсимволы LIKE в запросе экранируются).
     */
    public static function contains($query, string $column, string $term, string $boolean = 'and'): void
    {
        $needle = '%'.addcslashes(mb_strtolower($term, 'UTF-8'), '%_\\').'%';

        $query->whereRaw(self::lowerFn()."({$column}) LIKE ? ESCAPE '\\'", [$needle], $boolean);
    }

    /**
     * Условие «колонка равна значению» без учёта регистра.
     *
     * `whereRaw('LOWER(title) = ?', [mb_strtolower($t)])` для русских заголовков
     * НЕ работает: SQLite сворачивает регистр только у латиницы, поэтому
     * LOWER('Сеанс с Силами') остаётся как есть и никогда не совпадёт с
     * 'сеанс с силами'. Такой поиск молча не находит существующую страницу —
     * команда решает, что её нет.
     */
    public static function equals($query, string $column, string $value, string $boolean = 'and'): void
    {
        $query->whereRaw(self::lowerFn()."({$column}) = ?", [mb_strtolower($value, 'UTF-8')], $boolean);
    }

    /** Имя SQL-функции нижнего регистра для текущего соединения. */
    protected static function lowerFn(): string
    {
        if (self::isSqlite()) {
            self::registerLower();

            return 'xi_lower';
        }

        return 'LOWER';
    }

    protected static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** Регистронезависимость для кириллицы в SQLite: xi_lower() = mb_strtolower. */
    public static function registerLower(): void
    {
        $pdo = DB::connection()->getPdo();

        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('xi_lower', fn ($value) => mb_strtolower((string) $value, 'UTF-8'), 1);
        }
    }

    /** Русский алфавитный порядок для SQLite: коллация xi_ru (ICU, фолбэк — mb_strtolower). */
    public static function registerCollation(): void
    {
        $pdo = DB::connection()->getPdo();

        if (! method_exists($pdo, 'sqliteCreateCollation')) {
            return;
        }

        $collator = class_exists(\Collator::class) ? new \Collator('ru_RU') : null;

        $pdo->sqliteCreateCollation('xi_ru', $collator
            ? fn ($a, $b) => $collator->compare((string) $a, (string) $b)
            : fn ($a, $b) => strcmp(mb_strtolower((string) $a, 'UTF-8'), mb_strtolower((string) $b, 'UTF-8')));
    }
}
