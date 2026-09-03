# Backend status

What exists today, and what is still missing. Written after auditing the code, not from memory.

Stack: Laravel 13.26.1 · Filament 5.7.6 · MySQL 8 · Sanctum
Verified by 18 tests / 124 assertions (`php artisan test`).

---

## 1. Database — 25 migrations, 28 models

### Catalog
| Table | What it holds |
|---|---|
| `categories` | Self-nesting via `parent_id`. Slug, image, icon, SEO, sort order, active/featured flags |
| `goats` | Breed, age_months, weight_kg, gender, colour, teeth, health_status, is_vaccinated, `specs` JSON (admin-defined spec rows), price, sale_price, stock, track_stock, status (draft/published/sold/archived), is_featured, views, SEO. **Soft deletes** |
| `goat_images` | Multiple sortable photos per goat |

### Sales
| Table | What it holds |
|---|---|
| `carts` / `cart_items` | One persistent cart per customer, optional applied coupon |
| `orders` | Order number, customer + address **snapshot** (survives later address edits), subtotal/discount/delivery/total, currency, payment method + status + paid_amount, fulfilment status, delivered_at/cancelled_at, admin note |
| `order_items` | Product **snapshot** — name, SKU, thumbnail and unit price frozen at purchase time |
| `order_status_histories` | Every status change, who made it, optional note |
| `coupons` | Percent or fixed, min order, max discount, global + per-user usage caps, date window |
| `delivery_zones` | Charge, free-delivery threshold, estimated time |
| `payment_methods` | Code, instructions, logo, active flag, `config` JSON for gateway keys, advance-payment fields |

### Homestay
| Table | Notes |
|---|---|
| `rooms` | Type, sleeps, price per night, extra-guest fee, min/max nights, published flag |
| `room_images` | Gallery per room, first one is the card image |
| `bookings` | Dates, guests, nights, totals, payment plan, paid-to-date, status |
| `booking_nights` | One row per night held. Unique on `(room_id, night)`, which is what actually prevents a double booking |
| `booking_status_histories` | Same audit shape as `order_status_histories` |

Day-to-day operation is in [HOMESTAY-HOWTO.md](HOMESTAY-HOWTO.md); the flow end to
end is in [HOMESTAY-TUTORIAL.md](HOMESTAY-TUTORIAL.md).

### Customers
`users` (admins and customers share the table, split by `role`), `addresses` (one default enforced by a model hook), `wishlists`, `reviews` (admin-moderated).

### Admin-controlled content
`settings` (39 rows, grouped + typed), `banners`, `home_sections`, `pages`, `menus` / `menu_items`, `testimonials`, `faqs`, `posts` / `post_categories`, `contact_messages`, `inquiries`, `subscribers`.

---

## 2. REST API — 42 routes under `/api/v1`

### Public
| Route | Notes |
|---|---|
| `GET /site` | Settings + menus + footer pages. The whole site chrome in one call |
| `GET /home` | Ordered homepage sections, **each with its data already resolved** |
| `GET /goats` | Filters: category, breed, gender, min/max price, min/max weight, search, sort (7 modes), in_stock, pagination |
| `GET /goats/filters` | Filter options built from live stock, so new breeds appear automatically |
| `GET /goats/{slug}` | Detail + gallery + approved reviews + rating average. Increments `views` |
| `GET /goats/{slug}/related` | 4 from the same category |
| `GET /categories`, `/categories/{slug}` | Tree with published-goat counts |
| `GET /pages/{slug}` | CMS page |
| `GET /posts`, `/posts/{slug}`, `/post-categories` | Blog |
| `GET /faqs` | Optional `?group=` |
| `POST /contact`, `/subscribe`, `/goats/{slug}/inquiry` | Inbound messages |
| `POST /auth/register`, `/auth/login` | Returns a Sanctum token |

### Authenticated (`auth:sanctum`)
Account (`/auth/me`, `/auth/profile`, `/auth/password`, `/auth/logout` — password change revokes other tokens) ·
Cart (`/cart`, `/cart/items/{item}`, `/cart/coupon`) ·
Checkout (`/checkout/options`, `/checkout`) ·
Orders (`/orders`, `/orders/{orderNumber}`, `/orders/{orderNumber}/cancel`) ·
Addresses (full CRUD, ownership-checked) ·
Wishlist (`/wishlist`, `/wishlist/ids`, `/wishlist/toggle`) ·
Reviews (`POST /goats/{slug}/reviews`).

---

## 3. Business logic

**`CheckoutService`** — the whole order placement in one DB transaction:
- Locks the goat rows (`lockForUpdate`) so two buyers cannot claim the same animal
- Re-validates availability and stock at purchase time, not from the cart
- Recomputes subtotal server-side from live prices — client totals are never trusted
- Applies coupon (re-checked for redeemability), then delivery charge with free-delivery threshold
- Enforces the minimum-order setting
- Writes order + item snapshots, decrements stock, marks sold-out goats `sold`
- Increments coupon usage, empties the cart

**`OrderObserver`** — on every status change:
- Writes an `order_status_histories` row with the acting user
- Stamps `delivered_at` / `cancelled_at`
- Marks COD orders paid when delivered
- **Cancelling restocks the goats and puts them back on sale**

**`Coupon::isRedeemable()`** — active, date window, global cap, per-user cap, minimum order.
**`Setting`** — cached with `rememberForever`, busted automatically on save.

---

## 4. Admin panel (Filament) — 19 resources

| Group | Resources |
|---|---|
| Catalog | Goats, Categories |
| Sales | Orders, Coupons |
| Customers | Customers, Reviews |
| Storefront | Banners, Homepage sections, Pages, Menus, Testimonials, FAQs |
| Blog | Posts, Post categories |
| Inbox | Contact messages, Goat enquiries, Newsletter |
| Configuration | Site settings, Delivery zones, Payment methods |

**Hand-built screens**
- **Goats** — 5-tab form (Details / Livestock / Pricing & stock / Media / Publishing), repeater for custom spec rows, auto slug + SKU, gallery relation manager with drag-reorder, table with 8 filters, stock badges and a Duplicate action
- **Orders** — no manual create (orders come from checkout), read-only totals, one-click "Update status" action with a note, view page with infolist, items + status-history relation managers, pending-count nav badge
- **Site settings** — custom page rendering all 39 settings as tabs by group, with the right input per declared type (colour picker, file upload, toggle, number). New settings rows appear automatically
- **Menus** — drag-reorderable item manager with parent nesting
- **Users** — password optional on edit, role as a select, avatar upload
- **Homepage sections** — type select, JSON block-settings editor with validation, colour picker

**Dashboard** — revenue + month-to-date, order count with pending, goats in shop vs sold, customers with unread-message count, plus a latest-orders table.

---

## 5. Tests

`AdminPanelTest` — login page, customers refused panel access, all 19 list pages, dashboard, settings page, goat create/edit.
`AdminFormsTest` — every resource's create **and** edit screen renders.
`AdminSaveTest` — a no-op save does not damage a section's JSON config.
`AdminUserEditTest` — editing a customer does not reset their password.
`StorefrontApiTest` — public endpoints, goat detail + view counter, filters, auth-required routes, registration, **full purchase journey**, cancel-restocks-goat, coupon maths, contact form.

---

## Marketplace (selling)

Added after the buying side was complete. The shop went from single-vendor to
multi-vendor with moderated listings and a commission ledger.

### New tables
| Table | Purpose |
|---|---|
| `sellers` | Farm profile per user: identity, contact, vetting status, verification documents, optional commission override, payout details. Soft deletes |
| `payouts` | Settlement ledger — reference, amount, status, method, transaction reference |
| `goats` (+7 columns) | `seller_id` (null = house stock), `approval_status`, `rejection_reason`, `submitted_at`, `approved_at`, `approved_by` |
| `order_items` (+6 columns) | `seller_id`, `seller_name`, `commission_rate`, `commission_amount`, `seller_earning`, `payout_id` — all snapshotted at purchase |

### Rules enforced
- A goat is public only when **published + approved + its seller is in good standing**. The
  `published()` scope carries all three, so no endpoint can leak an unapproved listing.
- Suspending a seller removes their entire catalogue from the shop instantly.
- Approved listings are locked against editing, so a seller cannot swap the animal after
  moderation. Only drafts and rejected listings are editable.
- A seller can only ever read or write their own listings and their own order lines.
- Commission is frozen per line at purchase. Changing a rate later never moves a past sale.
- Earnings settle only on **delivered** orders. Payouts stamp the lines they cover inside a
  transaction, so double payment is impossible; a failed payout releases them again.
- The buyer's phone number is withheld from the seller until the order leaves `pending`.

### Who owns an order's status
Decided by who supplied the goods, not by role:

| Order contains | Runs it | Staff can |
|---|---|---|
| Goats from **one seller only** | That seller, `pending → … → delivered` | Watch, and cancel |
| Any **house stock** | Staff | Everything |
| Goats from **two or more sellers** | Staff — no single seller speaks for it | Everything |

- `Order::soleSellerId()` decides this. It **queries `order_items` directly** rather than
  reading the loaded relation: the seller's own order list eager-loads `items` filtered to
  that seller, and judging ownership from a filtered set would mark every mixed order
  seller-managed.
- On a seller-run order the admin's status select is disabled and the "Update status" row
  action is hidden. Tests assert both, and assert the opposite on a house order.
- **Cancel stays available to staff on every order.** It is an intervention, not a step:
  without it a disputed or fraudulent seller-run order could never be resolved.
- Sellers move forward only, and cannot cancel — they are told to contact staff.
- Payment status is never seller-editable.

### Who earns the delivery charge
The buyer pays a delivery charge on every order. It sits on the order, not on a line, so
it needs its own attribution:

| Order | Delivery charge goes to |
|---|---|
| Supplied entirely by one seller | **That seller** — they run the order and deliver it |
| Contains house stock, or two sellers | **The platform** — staff arrange transport |

- Stored as `orders.delivery_seller_id` + `delivery_earning`, set when the order is created.
  Kept off `order_items.seller_earning` on purpose so per-line maths stays auditable: a
  line's earning is always its own total minus its own commission.
- **No commission is taken on delivery** — it is a pass-through, not a sale.
- Payouts settle it alongside line earnings and stamp `delivery_payout_id`; a failed payout
  releases both. Without that the delivery money would have been owed forever and never paid.
- The seller's order list shows the whole breakdown — goats, commission, delivery, what they
  earn, and what the buyer actually paid — so the two sides of the money agree.
- The earnings statement carries a **Delivery** column beside Commission, with a totals row
  that reconciles against the summary tiles. Because delivery is earned **once per order**
  and a seller can have two goats on one order, it is credited to the first line of that
  order only — crediting every line would double-count it.

### Two bugs worth remembering
**A seller-run order ignored its own lines.** `syncOrderStatusFromLines()` used to bail out
for seller-managed orders, on the assumption that those sellers would only ever use the
order-level control. They can also reach the line endpoint — so a seller advanced their line
to `handed_over` while the order sat on `pending`, and the buyer's timeline never moved past
"Placed". The roll-up now applies to **every** order; both routes land in the same state.

**Earnings counted money that had not been earned.** `lifetime_earnings` summed every
non-cancelled sale, so an undelivered, unpaid order showed up as the seller's earnings.
It now counts **delivered orders only**, and a separate `pending_earnings` holds sold-but-
not-delivered money. The seller UI shows them as distinct lines so in-flight money can never
read as money in hand.

### Keeping the buyer's view in step
The buyer must see progress whoever reports it — a seller or the farm.

- **Every order line carries progress**, house stock included, and the buyer's order API
  returns it per goat along with `supplied_by`. A mixed order shows the buyer exactly which
  farm is at which stage.
- **Lines roll the order up.** Once every line reaches a stage, the order status follows:
  all `preparing`/`ready` → `processing`, all `handed_over` → `out_for_delivery`. The order
  can never run ahead of its slowest supplier.
- **The order carries down to lines.** When staff or a seller move the order, any lagging
  line is dragged forward, so the headline status and the per-goat badges never disagree.
- Both directions are **forward only**, so a manual staff decision is never rewound, and
  line writes do not trigger the roll-up again — there is no loop.
- The bug this fixed: a seller marked their line ready on a mixed order and the buyer saw
  nothing at all — the order stayed `pending` and the buyer's payload had no progress field.

### Seller line fulfilment (mixed and multi-seller orders)
- An order can hold goats from **several sellers plus house stock**, but `orders.status`
  is a single column. Handing it to a seller would let one of them mark an order delivered
  while another's animal has not even been collected.
- So each line carries its own `order_items.fulfilment_status`:
  `pending → preparing → ready → handed_over`, plus `cancelled`.
- A seller may only move **their own lines**, and only **forwards** — never rewind, never
  set `cancelled`, and never touch an order that has been cancelled.
- Marking a line **ready** emails staff with the seller's address and phone so collection
  can be arranged, including any note the seller attached.
- Cancelling an order cancels every seller line on it, so nobody is left preparing an
  animal for a sale that is off.
- Staff see each line's progress on the order and can override it to anything; the
  order-level delivery status remains the platform's, which matches the business model
  where the platform arranges transport and collects the money.

### Seller identity documents
- The application requires a **national ID number and an ID file**; a trade licence is
  optional. Validated in the browser for immediate feedback and again server-side.
- Files are stored under `sellers/documents` on the public disk. Replacing one deletes
  the previous file.
- Staff see every document on the seller record, with open and download actions, and the
  sellers list carries an ID-on-file indicator so nobody is approved without one.
- **Do not add these fields to `$hidden` on the Seller model.** `$hidden` strips them from
  `attributesToArray()`, which is what Filament fills forms from — that silently blanked
  the national ID, both documents and the payout account number in the admin panel while
  the data sat correctly in the database. Exposure is controlled in the API resources
  instead: `SellerResource` (public) omits them, `SellerProfileResource` (owner) masks the
  account number.

### Re-applying after a deleted application
- Only a **live** application blocks a new one. If staff delete one, the person can apply again.
- `sellers.user_id` is unique, so a resubmission **reuses the archived row** rather than
  inserting a second one — a plain insert would hit the constraint, and past `order_items`
  still reference that seller id.
- The revived row is reset to `pending` with the previous review note, approval timestamp
  and approver cleared, and the slug regenerated in case the farm was renamed. The slug
  helper ignores the row it is generating for, so resubmitting under the same name does not
  drift to `farm-name-2`.
- **Previously approved, unsold listings drop back to `pending`.** A re-approved seller must
  not silently resurrect listings that were cleared under the old application. Sold goats are
  left alone as history.
- Staff can Restore or permanently delete an archived application from the sellers list.

### Services
`PayoutService` (settle / markPaid / markFailed) and `ManualOrderService`, plus commission
handling inside `CheckoutService`.

### Notifications
`NewSellerApplicationNotification` (staff), `SellerApplicationReviewed` (approved / rejected /
suspended), `ListingReviewed` (approved / changes requested), `SellerSaleNotification` —
each seller hears only about their own lines.

### Permissions
New `marketplace` area, available to `admin` and `manager`, gating the Sellers and Payouts
screens.

---

# Gap review — closed

The four launch blockers from the first audit are now built and tested.

| # | Was missing | Now |
|---|---|---|
| 1 | **No email at all** | Four queued notifications: order confirmation to the customer, new-order alert to staff (mail + in-panel), status-change updates on every transition, and low-stock warnings. Sent only *after* the checkout transaction commits, so a rolled-back order never emails anyone |
| 2 | **No password reset** | `POST /auth/forgot-password` and `/auth/reset-password`. The link points at the storefront, not the API. The response is identical for unknown addresses so the endpoint cannot be used to discover accounts, and a successful reset revokes every existing API token |
| 3 | **No rate limiting** | Four named limiters: `auth` (5/min per email + 20/min per IP), `public-forms` (6/min), `checkout` (10/min), and `api` (90/min) applied to the whole API |
| 4 | **Advance payment half-built** | Finished. Checkout records `advance_required` from the chosen method, the API exposes `paid`/`balance_due`, and staff record part-payments through a **Record payment** action that moves the order to `partially_paid` or `paid` |

Also closed from the "important" list:

| Was missing | Now |
|---|---|
| No authorization | Four roles (`admin`, `manager`, `staff`, `customer`) with an area-based gate. Staff see only Sales and Inbox; managers run the shop but cannot reach settings, payment methods or delivery zones; only admins can grant roles |
| No admin-created orders | `ManualOrderService` + a **New phone order** screen. Same guarantees as the storefront: row locking, server-side totals, stock decrement, price snapshots, and a negotiated-price override |
| Hard deletes | Soft deletes on orders, customers, categories and reviews, each with a Trashed filter and restore/force-delete actions |
| No review moderation | Publish / hide actions plus a bulk publish, and a navigation badge counting reviews awaiting moderation |
| No inbox workflow | Mark read/unread, bulk mark-read and a "log reply" action on messages; contacted/closed actions on enquiries; unread badges on both |
| No low-stock alerting | The threshold now raises a real notification when an order pushes a goat to or below it |
| No sitemap | `/sitemap.xml` (storefront URLs, drafts excluded) and `/robots.txt` blocking `/admin` |
| Flat auto-generated forms | Goats, Orders, Users, Home sections, Banners, Categories, Pages, Posts, Coupons and Delivery zones are now grouped, with rich editors, image uploads and validation |

## Still open, deliberately

| Item | Why it is fine for now |
|---|---|
| Email verification | `email_verified_at` stays unused. Orders are confirmed by phone, so a verified email is not on the critical path |
| Invoice PDF | Needs a rendering package. Order data is complete, so this can be added without schema changes |
| Full-text search | `LIKE` is fine at this catalogue size. Revisit past a few thousand listings |
| Image optimisation | Uploads are stored at original size. Add a resize pipeline if page weight becomes a problem |
| Multi-currency / multi-language | Single currency and English only |
| Bot-aware view counting | `views` increments on every detail request |
| Delivery zone vs city validation | The customer picks their zone; nothing cross-checks it against the city they typed |
| General audit log | Only order status changes are recorded |

## Locale — the shop runs in Nepal

| Setting | Value |
|---|---|
| Currency | NPR, symbol `रु`, shown before the amount |
| Digit grouping | `en-IN` — Nepal groups in lakhs, so `रु1,50,000`, not `रु150,000` |
| Timezone | `Asia/Kathmandu` (UTC+5:45) |
| Phone format | `+977` |
| Delivery zones | Kathmandu Valley / Around the Valley / Rest of Nepal |
| Wallets seeded | eSewa and Khalti (both off until their keys are added) |

**Catalog is built around Dashain, with Qurbani kept alongside.** Dashain is Nepal's main
goat season, so it sorts first and carries most of the stock; Qurbani remains for the
Muslim community rather than being removed. The native **Khari** breed is stocked, and
listings use local terms (*khasi* for a castrated male).

**Two seeder traps fixed while localising:**
- `UserSeeder` used `firstOrCreate` for the customer address, so re-seeding silently left
  the old address in place. Now `updateOrCreate`.
- `ContentSeeder` keyed hero banners on their **title**, so changing the wording created a
  new banner and orphaned the old one — a stale "Free delivery inside Dhaka" survived that
  way. Banners are now keyed on `placement` + `sort_order`, i.e. the slot, not the words.
- Goats are keyed on an index-derived SKU, so new catalog entries must be **appended**;
  inserting at the top re-maps every existing goat onto a different SKU.

**Never hardcode currency again.** `Setting::currencyCode()`, `Setting::currencySymbol()`
and `Setting::numberLocale()` are the only places a fallback lives. Ten files previously
carried a `'BDT'` or `'৳'` literal, and the admin order form ignored the setting entirely —
it printed `৳` no matter what Site settings said.

`config/app.php` shipped with `'timezone' => 'UTC'` hardcoded rather than reading
`APP_TIMEZONE`, so setting the env var alone did nothing. It now reads the env var.

On the storefront, `formatMoney()` takes the locale from settings, so grouping follows the
shop rather than a hardcoded `en-US`.

## Operational notes

- `QUEUE_CONNECTION=sync` so notifications send inline with no worker. For production set it to `database` and run `php artisan queue:work`.
- `MAIL_MAILER=log` writes emails to `storage/logs/laravel.log`. Point it at real SMTP before launch.
- The test suite runs against the `goat_marketplace_test` MySQL database, not SQLite, so migrations are exercised on the real engine.
- The storefront has no root `app/loading.js`. A root-level loading file makes every route
  stream, which commits a 200 before `notFound()` can run and turns every missing goat, page
  and seller into a soft 404. Add loading states per segment instead.
