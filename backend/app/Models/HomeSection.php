<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HomeSection extends Model
{
    protected $fillable = [
        'type', 'title', 'subtitle', 'description', 'config',
        'custom_html', 'background_color', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'config'    => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** Section types the admin can add, and what each one renders. */
    public const TYPES = [
        'hero_slider'    => 'Hero slider',
        'categories'     => 'Category grid',
        'featured_goats' => 'Featured goats',
        'latest_goats'   => 'Latest goats',
        'promo_banner'   => 'Promo banner',
        'why_choose_us'  => 'Why choose us',
        'testimonials'   => 'Testimonials',
        'faq'            => 'FAQ',
        'blog'           => 'Latest blog posts',
        'cta'            => 'Call to action',
        'custom_html'    => 'Custom HTML',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
