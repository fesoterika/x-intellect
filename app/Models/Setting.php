<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ключ-значение настроек сайта (режим техработ и т.п.).
 * Чтение идёт через вечный кеш: настройка проверяется на каждом
 * запросе (middleware), лишний SQL-запрос ни к чему.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const MAINTENANCE = 'maintenance';

    public static function get(string $key, ?string $default = null): ?string
    {
        // false - маркер «строки нет»: null кеш не хранит (считает промахом)
        $value = Cache::rememberForever(
            "setting:{$key}",
            fn () => static::find($key)->value ?? false,
        );

        return $value === false ? $default : $value;
    }

    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forever("setting:{$key}", $value);
    }

    public static function maintenanceEnabled(): bool
    {
        return static::get(self::MAINTENANCE) === '1';
    }
}
