<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $fillable = ['label', 'url', 'location', 'position', 'parent_id'];

    public function scopeLocation(Builder $query, string $location): Builder
    {
        return $query->where('location', $location)->orderBy('position');
    }
}
