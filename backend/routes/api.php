<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\GoatController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SellerAccountController;
use App\Http\Controllers\Api\V1\SellerDirectoryController;
use App\Http\Controllers\Api\V1\SellerListingController;
use App\Http\Controllers\Api\V1\SellerSalesController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\WishlistController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public — everything the storefront can read without an account
    |--------------------------------------------------------------------------
    */
    Route::get('site', [SiteController::class, 'index'])->name('site');
    Route::get('home', [HomeController::class, 'index'])->name('home');

    Route::get('goats', [GoatController::class, 'index'])->name('goats.index');
    Route::get('goats/filters', [GoatController::class, 'filters'])->name('goats.filters');
    Route::get('goats/{slug}', [GoatController::class, 'show'])->name('goats.show');
    Route::get('goats/{slug}/related', [GoatController::class, 'related'])->name('goats.related');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');

    Route::get('pages/{slug}', [ContentController::class, 'page'])->name('pages.show');
    Route::get('posts', [ContentController::class, 'posts'])->name('posts.index');
    Route::get('post-categories', [ContentController::class, 'postCategories'])->name('posts.categories');
    Route::get('posts/{slug}', [ContentController::class, 'post'])->name('posts.show');
    Route::get('faqs', [ContentController::class, 'faqs'])->name('faqs.index');

    Route::get('sellers', [SellerDirectoryController::class, 'index'])->name('sellers.index');
    Route::get('sellers/{slug}', [SellerDirectoryController::class, 'show'])->name('sellers.show');

    Route::middleware('throttle:public-forms')->group(function () {
        Route::post('contact', [ContactController::class, 'store'])->name('contact.store');
        Route::post('subscribe', [ContactController::class, 'subscribe'])->name('subscribe');
        Route::post('goats/{slug}/inquiry', [ContactController::class, 'inquiry'])->name('goats.inquiry');
    });

    /*
    |--------------------------------------------------------------------------
    | Guest-only auth
    |--------------------------------------------------------------------------
    */
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('auth.forgot');
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->name('auth.reset');
    });

    /*
    |--------------------------------------------------------------------------
    | Authenticated — cart, checkout and the customer account
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::put('auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile');
        Route::put('auth/password', [AuthController::class, 'changePassword'])->name('auth.password');

        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::post('cart', [CartController::class, 'store'])->name('cart.store');
        Route::put('cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('cart/items/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
        Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

        Route::get('checkout/options', [CheckoutController::class, 'options'])->name('checkout.options');
        Route::post('checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:checkout')
            ->name('checkout.store');

        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{orderNumber}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{orderNumber}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

        Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::put('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
        Route::get('wishlist/ids', [WishlistController::class, 'ids'])->name('wishlist.ids');
        Route::post('wishlist/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

        Route::post('goats/{slug}/reviews', [ReviewController::class, 'store'])->name('goats.reviews.store');

        /*
        |----------------------------------------------------------------------
        | Seller area
        |----------------------------------------------------------------------
        |
        | Applying and reading your own profile work at any status, so a pending
        | or rejected applicant can see where they stand. Everything that touches
        | listings or money is behind the `seller` middleware, which requires an
        | approved account.
        |
        */
        Route::post('seller/apply', [SellerAccountController::class, 'apply'])
            ->middleware('throttle:public-forms')
            ->name('seller.apply');
        Route::get('seller/profile', [SellerAccountController::class, 'show'])->name('seller.profile');
        Route::put('seller/profile', [SellerAccountController::class, 'update'])->name('seller.profile.update');
        Route::post('seller/documents', [SellerAccountController::class, 'updateDocuments'])
            ->middleware('throttle:public-forms')
            ->name('seller.documents');

        Route::middleware('seller')->group(function () {
            Route::get('seller/dashboard', [SellerAccountController::class, 'dashboard'])->name('seller.dashboard');

            Route::get('seller/listings', [SellerListingController::class, 'index'])->name('seller.listings.index');
            Route::post('seller/listings', [SellerListingController::class, 'store'])->name('seller.listings.store');
            Route::get('seller/listings/{goat}', [SellerListingController::class, 'show'])->name('seller.listings.show');
            Route::put('seller/listings/{goat}', [SellerListingController::class, 'update'])->name('seller.listings.update');
            Route::delete('seller/listings/{goat}', [SellerListingController::class, 'destroy'])->name('seller.listings.destroy');
            Route::post('seller/listings/{goat}/submit', [SellerListingController::class, 'submit'])->name('seller.listings.submit');

            Route::get('seller/orders', [SellerSalesController::class, 'orders'])->name('seller.orders');
            Route::put('seller/order-items/{item}/status', [SellerSalesController::class, 'updateItemStatus'])
                ->name('seller.orders.item-status');
            Route::put('seller/orders/{orderNumber}/status', [SellerSalesController::class, 'updateOrderStatus'])
                ->name('seller.orders.status');
            Route::get('seller/earnings', [SellerSalesController::class, 'earnings'])->name('seller.earnings');
            Route::get('seller/payouts', [SellerSalesController::class, 'payouts'])->name('seller.payouts');
        });
    });
});
