<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This application is an API and an admin panel. The storefront is the
| separate Next.js app, so the root simply points people at it, and the
| SEO files are generated here because this is where the data lives.
|
*/

Route::redirect('/', '/admin')->name('home');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');
