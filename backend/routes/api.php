<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AnimalController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\GatewayPaymentController;
use App\Http\Controllers\Api\V1\GoatController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RoomController;
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

    /*
    |--------------------------------------------------------------------------
    | The farm's rooms
    |--------------------------------------------------------------------------
    |
    | Public, because somebody works out where they will sleep before they sign
    | in -- and because a room page that demanded an account to read the price
    | would lose the guest at the first question.
    |
    | `rooms/options` is declared above `rooms/{slug}`, or the router would
    | match "options" as a slug and answer 404 for a page that exists. The goat
    | filters endpoint sits the same way and for the same reason.
    |
    */
    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::get('rooms/options', [RoomController::class, 'options'])->name('rooms.options');
    Route::get('rooms/{slug}', [RoomController::class, 'show'])->name('rooms.show');
    Route::get('rooms/{slug}/availability', [RoomController::class, 'availability'])
        ->name('rooms.availability');

    Route::middleware('throttle:public-forms')->group(function () {
        Route::post('contact', [ContactController::class, 'store'])->name('contact.store');
        Route::post('subscribe', [ContactController::class, 'subscribe'])->name('subscribe');
        Route::post('goats/{slug}/inquiry', [ContactController::class, 'inquiry'])->name('goats.inquiry');
    });

    /*
    |--------------------------------------------------------------------------
    | A scanned ear tag
    |--------------------------------------------------------------------------
    |
    | Open on purpose: whoever is holding the goat should be able to check it,
    | and at the gate that is the buyer, not staff. The token in the URL is
    | random, so this cannot be used to read the pen by counting upwards.
    |
    */
    Route::get('animals/{token}', [AnimalController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{16,64}')
        ->name('animals.show');

    Route::get('animals/{token}/qr', [AnimalController::class, 'qr'])
        ->where('token', '[A-Za-z0-9]{16,64}')
        ->name('animals.qr');

    /*
    |--------------------------------------------------------------------------
    | Where a payment provider sends the buyer back
    |--------------------------------------------------------------------------
    |
    | Unauthenticated by necessity: the browser arrives from esewa.com.np or
    | khalti.com carrying no token of ours. It is safe to leave open because
    | the query string only names an attempt -- what actually happened is
    | settled by asking the provider, server to server, before any money is
    | recorded. Both providers may send the buyer here more than once, and
    | the handler is written to be repeated.
    |
    */
    Route::get('payments/{gateway}/return', [GatewayPaymentController::class, 'returned'])
        ->where('gateway', 'esewa|khalti')
        ->name('payments.return');

    /*
    |--------------------------------------------------------------------------
    | Guest-only auth
    |--------------------------------------------------------------------------
    */

    /*
     * Signing in through Google, for both the sign-in and the sign-up page.
     *
     * Kept out of the throttle:auth group on purpose. That limiter keys partly
     * on the submitted email address, and this request carries none -- every
     * Google sign-in on the site would share one bucket of five a minute. Its
     * own limiter keys on the IP instead.
     *
     * No robot check here either: arriving with a Google ID token has already
     * proved there is a person on the other end.
     */
    Route::post('auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:google-auth')
        ->name('auth.google');

    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('auth.forgot');

        // Finishing a signup, and asking again when the first code went astray.
        // Throttled with the rest of auth: these send mail on demand.
        Route::post('auth/verify-email', [AuthController::class, 'verifyEmail'])->name('auth.verify');
        Route::post('auth/resend-verification', [AuthController::class, 'resendVerification'])
            ->name('auth.resend');
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->name('auth.reset');
    });

    /*
    |--------------------------------------------------------------------------
    | Authenticated — cart, checkout and the customer account
    |--------------------------------------------------------------------------
    */
    // `active` sits alongside the guard so a token that outlived its account
    // being disabled cannot reach any of this.
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
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

        // Every payment and refund the buyer has made, across all their orders.
        // The per-order history answers "is this one settled"; this answers
        // "what have I paid this shop", which no order page can.
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

        /*
         * The bell. Read-and-mark only: nothing here creates a notification,
         * because a notification is something the shop tells you, never
         * something a browser can assert about itself.
         */
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        // Declared before the {id} route, or "read-all" is swallowed as an id.
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])
            ->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])
            ->name('notifications.read');
        Route::get('orders/{orderNumber}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{orderNumber}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        // Collection only: the buyer telling us they have set off.
        Route::post('orders/{orderNumber}/on-my-way', [OrderController::class, 'onMyWay'])
            ->name('orders.on-my-way');

        Route::post('orders/{orderNumber}/received', [OrderController::class, 'confirmReceipt'])
            ->name('orders.received');
        Route::post('orders/{orderNumber}/payments', [OrderController::class, 'pay'])
            ->middleware('throttle:public-forms')
            ->name('orders.pay');

        // Opening an online payment. Same throttle as the manual claim it
        // replaces -- a burst of these means something is wrong, not eager.
        Route::post('orders/{orderNumber}/pay/{gateway}', [GatewayPaymentController::class, 'start'])
            ->middleware('throttle:public-forms')
            ->name('orders.pay.gateway');
        Route::post('orders/{orderNumber}/refunds', [OrderController::class, 'refund'])
            ->middleware('throttle:public-forms')
            ->name('orders.refund');

        /*
        |----------------------------------------------------------------------
        | Booking a room
        |----------------------------------------------------------------------
        |
        | Behind the account guard, unlike reading about one: a room is held
        | for a named person who has to be reachable, and the stay has to appear
        | somewhere they can find it again.
        |
        | Placing one is throttled with the checkout, which is the same shape of
        | request -- generous for a person, tight for a script hunting for the
        | last room of a festival weekend.
        |
        */
        Route::post('rooms/{slug}/bookings', [BookingController::class, 'store'])
            ->middleware('throttle:checkout')
            ->name('rooms.book');

        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{number}', [BookingController::class, 'show'])->name('bookings.show');
        Route::post('bookings/{number}/cancel', [BookingController::class, 'cancel'])
            ->name('bookings.cancel');

        Route::post('bookings/{number}/payments', [BookingController::class, 'pay'])
            ->middleware('throttle:public-forms')
            ->name('bookings.pay');

        // Opening an online payment for a room. The provider sends the guest
        // back to the same handler an order's payment returns to -- it settles
        // whatever the attempt was for.
        Route::post('bookings/{number}/pay/{gateway}', [GatewayPaymentController::class, 'startForBooking'])
            ->middleware('throttle:public-forms')
            ->name('bookings.pay.gateway');

        Route::post('bookings/{number}/refunds', [BookingController::class, 'refund'])
            ->middleware('throttle:public-forms')
            ->name('bookings.refund');

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
        Route::put('seller/payout-details', [SellerAccountController::class, 'updatePayoutDetails'])
            ->name('seller.payout-details');
        Route::get('seller/payout-methods', [SellerAccountController::class, 'payoutMethods'])
            ->name('seller.payout-methods');
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
            Route::post('seller/listings/{goat}/images', [SellerListingController::class, 'uploadImages'])
                ->name('seller.listings.images.store');
            Route::delete('seller/listings/{goat}/images/{image}', [SellerListingController::class, 'deleteImage'])
                ->name('seller.listings.images.destroy');

            Route::get('seller/orders', [SellerSalesController::class, 'orders'])->name('seller.orders');
            Route::put('seller/order-items/{item}/status', [SellerSalesController::class, 'updateItemStatus'])
                ->name('seller.orders.item-status');
            Route::put('seller/orders/{orderNumber}/status', [SellerSalesController::class, 'updateOrderStatus'])
                ->name('seller.orders.status');
            Route::get('seller/earnings', [SellerSalesController::class, 'earnings'])->name('seller.earnings');
            Route::get('seller/payouts', [SellerSalesController::class, 'payouts'])->name('seller.payouts');
            Route::post('seller/payouts', [SellerSalesController::class, 'requestPayout'])
                ->name('seller.payouts.request');
        });
    });
});
