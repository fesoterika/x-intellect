<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class MenuItem extends Model
{
    protected $fillable = ['label', 'url', 'location', 'position', 'parent_id'];

    /** Меню в рамках запроса: composer site.* зовёт tree() на каждый partial. */
    protected static array $memo = [];

    /**
     * Дерево меню локации (корни с детьми) из вечного кеша: меню меняется
     * только из админки, а читается на каждом публичном запросе.
     * В кеше — простые массивы атрибутов (database-store с
     * serializable_classes=false объекты не десериализует), модели
     * собираются обратно через hydrate().
     */
    public static function tree(string $location): Collection
    {
        return static::$memo[$location] ??= static::hydrateTree(Cache::rememberForever(
            "menu:{$location}",
            fn () => static::location($location)->root()->with('children')->get()
                ->map(fn (self $root) => [
                    'item' => $root->attributesToArray(),
                    'children' => $root->children->map->attributesToArray()->all(),
                ])->all(),
        ));
    }

    /** @param array<int, array{item: array, children: array}> $rows */
    protected static function hydrateTree(array $rows): Collection
    {
        return static::hydrate(array_column($rows, 'item'))
            ->each(fn (self $root, int $i) => $root->setRelation(
                'children',
                static::hydrate($rows[$i]['children']),
            ));
    }

    protected static function booted(): void
    {
        $flush = function (): void {
            static::$memo = [];
            Cache::forget('menu:header');
            Cache::forget('menu:footer');
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function scopeLocation(Builder $query, string $location): Builder
    {
        return $query->where('location', $location)->orderBy('position');
    }

    /** Корневые пункты (для рендера меню и выбора родителя в админке). */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
