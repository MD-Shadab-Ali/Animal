<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    protected $fillable = ['name', 'slug'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /** Only top-level items; children come through the nested relation. */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}
