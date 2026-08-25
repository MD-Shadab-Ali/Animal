<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class SiteController extends Controller
{
    /**
     * Everything the storefront chrome needs: settings, navigation and footer pages.
     * The frontend calls this once and caches it.
     */
    public function index(): JsonResponse
    {
        $settings = Setting::all_values();

        $settings['copyright_text'] = str_replace(
            '{year}',
            (string) now()->year,
            (string) ($settings['copyright_text'] ?? '')
        );

        $menus = Menu::with(['rootItems' => fn ($q) => $q->where('is_active', true), 'rootItems.children'])
            ->get()
            ->mapWithKeys(fn (Menu $menu) => [
                $menu->slug => $menu->rootItems->map(fn (MenuItem $item) => $this->menuItem($item))->values(),
            ]);

        $footerPages = Page::active()
            ->where('show_in_footer', true)
            ->orderBy('sort_order')
            ->get(['title', 'slug'])
            ->map(fn (Page $page) => [
                'title' => $page->title,
                'slug'  => $page->slug,
                'url'   => '/pages/'.$page->slug,
            ]);

        return response()->json([
            'data' => [
                'settings'     => $settings,
                'menus'        => $menus,
                'footer_pages' => $footerPages,
            ],
        ]);
    }

    private function menuItem(MenuItem $item): array
    {
        return [
            'label'    => $item->label,
            'url'      => $item->url,
            'icon'     => $item->icon,
            'new_tab'  => $item->open_in_new_tab,
            'children' => $item->children->map(fn (MenuItem $child) => $this->menuItem($child))->values(),
        ];
    }
}
