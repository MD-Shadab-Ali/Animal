# Goat Marketplace

An e-commerce site for selling goats, built as two applications that talk over a REST API.

| Layer | Technology | Runs on |
|---|---|---|
| Storefront | Next.js 16 (App Router) + Bootstrap 5 | `http://localhost:3000` |
| API + Admin | Laravel 13 + Filament 5 | `http://localhost:8000` |
| Database | MySQL 8 (`goat_marketplace`) | `127.0.0.1:3306` |
| Auth | Laravel Sanctum tokens | — |
| Payment | Cash on Delivery (pluggable) | — |
| Locale | Nepal — NPR (`रु`), `Asia/Kathmandu`, lakh grouping | — |

**Everything on the storefront is dynamic.** Site name, logo, brand colours, navigation
menus, homepage sections, banners, pages, delivery charges, payment methods and every
product detail are read from the database and edited in the admin panel. Changing a
colour or reordering the homepage needs no code and no deploy.

---

## Running it

Both apps need to be running at the same time.

### 1. Backend (Laravel + Filament)

```bash
cd backend
php artisan serve
```

Admin panel: **http://localhost:8000/admin**

| | |
|---|---|
| Email | `admin@goathaven.test` |
| Password | `password` |

**Roles.** Panel access is split four ways, so not everyone sees everything:

| Role | Can reach |
|---|---|
| `admin` | Everything, including settings, staff accounts, payment methods and delivery zones |
| `manager` | Catalog, sales, customers, content and blog — no configuration |
| `staff` | Sales and the message inbox only |
| `customer` | Storefront only; blocked from the panel |

### 2. Frontend (Next.js)

```bash
cd frontend
npm run dev
```

Storefront: **http://localhost:3000**

Two more seeded accounts:

| Account | Login | What it is |
|---|---|---|
| Customer | `customer@example.test` / `password` | Buys goats |
| Seller | `seller@example.test` / `password` | An approved seller with one live listing and one awaiting review |

---

## First-time setup on another machine

```bash
# Database
mysql -u root -e "CREATE DATABASE goat_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link

# Frontend
cd ../frontend
npm install
```

`frontend/.env.local` points at the API:

```
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api/v1
NEXT_PUBLIC_BACKEND_URL=http://127.0.0.1:8000
```

The backend allows the storefront origin through CORS via `FRONTEND_URL` in `backend/.env`.
That same value builds the links inside customer emails, so it must point at the storefront.

### Email and queues

Notifications (order confirmation, status updates, new-order and low-stock alerts) are
queued jobs. Out of the box `QUEUE_CONNECTION=sync` runs them inline and
`MAIL_MAILER=log` writes them to `backend/storage/logs/laravel.log`, so everything works
with no extra processes.

For production, point `MAIL_*` at real SMTP and switch to a worker:

```bash
# backend/.env
QUEUE_CONNECTION=database
```

```bash
php artisan queue:work
```

---

## What the admin can change

Every item below is editable in Filament with no code change.

| Admin screen | Controls |
|---|---|
| **Configuration → Site settings** | Site name, logo, favicon, tagline, brand colours, contact details, socials, currency and symbol, announcement bar, SEO defaults, analytics IDs, feature toggles for coupons/reviews/wishlist |
| **Storefront → Homepage sections** | Which blocks appear on the homepage, their order, titles, and per-block settings. Switch a section off and it vanishes from the site |
| **Storefront → Banners** | Hero slider entries with headline, image, CTA and an optional schedule window |
| **Storefront → Menus** | Header and footer navigation, built as drag-and-drop items |
| **Storefront → Pages** | About, Terms, Privacy, Delivery — any CMS page with its own SEO tags |
| **Storefront → Testimonials / FAQs** | Customer quotes and the FAQ accordion |
| **Catalog → Goats** | Breed, age, weight, gender, colour, teeth, health, photo gallery, price, sale price, stock, custom spec rows, SEO |
| **Catalog → Categories** | Nested categories with images and descriptions |
| **Sales → Orders** | Order status with a full audit trail, payment status, internal notes |
| **Sales → Coupons** | Percentage or fixed discounts with caps, date windows and usage limits |
| **Configuration → Delivery zones** | Zone names, charges, free-delivery thresholds, estimated times |
| **Configuration → Payment methods** | Enable or disable methods; COD ships enabled, bKash and bank transfer are seeded but off |
| **Blog** | Care guides with categories |
| **Inbox** | Contact messages, per-goat enquiries, newsletter subscribers |

### How the dynamic homepage works

`GET /api/v1/home` returns an ordered list of sections. Each carries its `type`, its
admin-entered copy, its `config`, and the **data already resolved** for it:

```json
[
  { "type": "hero_slider",    "data": [ ...banners ] },
  { "type": "featured_goats", "title": "Featured goats", "config": { "limit": 8 }, "data": [ ...goats ] },
  { "type": "stats",          "title": "Our numbers", "config": { "items": [ ... ] } }
]
```

The storefront maps `type` to a component in
[`components/home/SectionRenderer.js`](frontend/components/home/SectionRenderer.js).
Reordering or hiding a section in the admin changes the page immediately. Adding a
brand-new *kind* of section is the only thing that needs a code change — one `case`
in that file.

---

## Order lifecycle

```
pending → confirmed → processing → out_for_delivery → delivered
                  ↘ cancelled (any time before delivery)
```

- Every transition is written to `order_status_histories` with who made it.
- Placing an order decrements stock inside a locked transaction, so two customers
  cannot buy the same goat.
- A goat that hits zero stock is marked `sold` and drops out of the shop.
- Cancelling an order puts the goat back on sale automatically.
- Marking a COD order `delivered` also marks it paid.

---

## Tests

```bash
cd backend
php artisan test
```

15 tests / 112 assertions covering:

- every admin screen renders, and customers are refused entry to the panel
- all public API endpoints and their shapes
- registration issuing a Sanctum token
- the full purchase journey: cart → checkout → order → stock decrement → history
- cancelling an order restocking the goat
- coupon discounts, shop filters, and the contact form reaching the inbox

Frontend:

```bash
cd frontend
npm run lint    # clean
npm run build   # 18 routes
```

---

## API reference

All endpoints are prefixed `/api/v1`. Authenticated routes need
`Authorization: Bearer <token>`.

**Public**

Rate limits: `auth` endpoints allow 5 attempts a minute per email (20 per IP), public
forms 6 a minute, checkout 10 a minute, and everything else 90 a minute.

| Method | Path | Purpose |
|---|---|---|
| GET | `/site` | Settings, menus, footer pages — the whole site chrome |
| GET | `/home` | Ordered homepage sections with their data |
| GET | `/goats` | Shop listing. Filters: `category`, `breed`, `gender`, `min_price`, `max_price`, `min_weight`, `max_weight`, `search`, `sort`, `in_stock`, `page`, `per_page` |
| GET | `/goats/filters` | Filter options built from live stock |
| GET | `/goats/{slug}` | Goat detail with gallery and reviews |
| GET | `/goats/{slug}/related` | Four more from the same category |
| GET | `/categories`, `/categories/{slug}` | Category tree |
| GET | `/pages/{slug}` | CMS page |
| GET | `/posts`, `/posts/{slug}`, `/post-categories` | Blog |
| GET | `/faqs` | FAQs, optionally `?group=` |
| POST | `/contact`, `/subscribe`, `/goats/{slug}/inquiry` | Inbound messages |
| POST | `/auth/register`, `/auth/login` | Returns a Sanctum token |
| POST | `/auth/forgot-password`, `/auth/reset-password` | Password reset, link lands on the storefront |

**Authenticated**

| Method | Path | Purpose |
|---|---|---|
| GET/PUT | `/auth/me`, `/auth/profile`, `/auth/password` | Account |
| POST | `/auth/logout` | Revoke the current token |
| GET/POST/DELETE | `/cart`, `/cart/items/{item}`, `/cart/coupon` | Cart and coupons |
| GET | `/checkout/options` | Active delivery zones and payment methods |
| POST | `/checkout` | Place the order |
| GET | `/orders`, `/orders/{orderNumber}` | Order history |
| POST | `/orders/{orderNumber}/cancel` | Cancel while still cancellable |
| GET/POST/PUT/DELETE | `/addresses` | Saved addresses |
| GET/POST | `/wishlist`, `/wishlist/toggle` | Wishlist |
| POST | `/goats/{slug}/reviews` | Review a delivered goat |

**Seller area** (authenticated; everything below `seller/` past the profile also requires an approved seller account)

| Method | Path | Purpose |
|---|---|---|
| POST | `/seller/apply` | Apply to sell |
| GET/PUT | `/seller/profile` | Read or update the seller profile — readable at any status |
| GET | `/seller/dashboard` | Listing, sales and earnings summary |
| GET/POST | `/seller/listings` | List or create own listings |
| GET/PUT/DELETE | `/seller/listings/{goat}` | Manage one listing |
| POST | `/seller/listings/{goat}/submit` | Send it for review |
| GET | `/seller/orders` | Orders containing their goats, their lines only |
| PUT | `/seller/order-items/{item}/status` | Move one of their own lines forward (`preparing`, `ready`, `handed_over`) |
| PUT | `/seller/orders/{orderNumber}/status` | Run a whole order they supplied in full, through to `delivered` |
| GET | `/seller/earnings`, `/seller/payouts` | Statement and payout history |
| GET | `/sellers`, `/sellers/{slug}` | Public seller directory and profile |

---

## Adding a payment gateway later

The checkout reads `payment_methods` from the database, so a new gateway is:

1. Enable the row in **Configuration → Payment methods** (bKash and bank transfer
   are already seeded, switched off) and put its keys in the `config` JSON column.
2. Handle the new `code` in
   [`app/Services/CheckoutService.php`](backend/app/Services/CheckoutService.php)
   where the order is created.

Nothing on the storefront needs changing — it renders whatever methods the API returns.

---

## Selling — the marketplace side

The site is a multi-vendor marketplace, not just the farm's own shop. Anyone with an
account can apply to sell.

```
apply  →  admin verifies the seller  →  seller creates a listing
       →  admin approves the listing  →  it appears in the shop
       →  buyer orders  →  delivered  →  earnings settle  →  payout
```

**Nothing reaches the shop unreviewed.** A goat is public only when its owner has
published it *and* staff have approved it *and* the seller is still in good standing —
suspending a seller pulls every one of their goats out of the shop immediately.

| Screen | Who | Purpose |
|---|---|---|
| `/sell` | Anyone | How selling works, and the application form |
| `/seller` | Seller | Listing counts, sales and earnings at a glance |
| `/seller/listings` | Seller | Create, edit, submit for review, delete drafts |
| `/seller/orders` | Seller | Their own lines only, and where they move each sale forward |
| `/seller/earnings` | Seller | Per-sale statement and payout history |
| `/sellers/{slug}` | Anyone | Public farm profile with their live goats |
| Admin → Marketplace → Sellers | Staff | Approve, reject, suspend, set commission, trigger payouts |
| Admin → Marketplace → Payouts | Staff | Mark paid or failed |
| Admin → Catalog → Goats | Staff | Approve or reject listings, with a reason sent to the seller |

### Applying to sell

The application collects the farm's details plus proof of identity:

| Field | Required | Notes |
|---|---|---|
| National ID number | Yes | Typed by the applicant |
| ID document | **Yes** | Photo or scan of NID, passport or driving licence. JPG, PNG, WEBP or PDF, max 5MB |
| Trade licence | No | Accepted if they have one — most smallholders do not |

The ID file is validated in the browser (type and size, so errors are immediate) and again
by the API, which is the authority. Documents are stored on the public disk under
`sellers/documents` and are visible to **staff in the admin panel** and to the **seller
themselves** — never through the public seller directory. Re-uploading through
`POST /seller/documents` deletes the file it replaces, so old ID scans do not accumulate.

### Money

Commission is **snapshotted onto each order line at purchase time** — `commission_rate`,
`commission_amount` and `seller_earning` are frozen, so changing a rate later never moves
a settled sale. House stock (no seller) carries no commission.

Earnings become payable only once an order is **delivered**. A payout stamps its
`payout_id` onto the lines it settles inside a transaction, so the same earning can never
be paid twice; marking a payout failed releases those lines back into the queue.

The commission rate, minimum payout, and whether applications are open at all are
settings in **Configuration → Site settings → Marketplace**.

## Design system

The storefront follows a documented design system rather than ad-hoc styling —
see [`design-system/goat-haven/MASTER.md`](design-system/goat-haven/MASTER.md).

| Decision | Value |
|---|---|
| Pattern | Marketplace / directory — the search bar is the primary call to action |
| Style | Organic biophilic: earth green, generous rounding, natural shadows |
| Colours | Primary `#15803D`, secondary `#22C55E`, accent `#A16207` (harvest gold, reserved for the main CTA) |
| Typography | Rubik for headings, Nunito Sans for body |
| Motion | 220–340ms, transform-only reveals, `prefers-reduced-motion` respected |

Colours stay admin-editable: the three brand values come from Site settings and are
injected as CSS variables, so changing them in Filament restyles the whole storefront.

Accessibility baseline: visible focus rings, 44px minimum tap targets, labelled
icon-only buttons, alt text on imagery, and no content that depends on an animation
completing in order to be visible.

## Project layout

```
Animal Marketplace/
├── backend/
│   ├── app/
│   │   ├── Filament/          19 resources, settings page, dashboard widgets
│   │   ├── Http/              API controllers + JSON resources
│   │   ├── Models/            28 Eloquent models
│   │   ├── Observers/         Order status history + restocking
│   │   └── Services/          CheckoutService
│   ├── database/
│   │   ├── migrations/        25 migrations
│   │   └── seeders/           Settings, catalog, content, navigation, blog
│   ├── routes/api.php         42 routes
│   └── tests/Feature/
├── frontend/
│   ├── app/                   Routes (App Router)
│   ├── components/            layout, home, goat, cart, checkout, account
│   ├── context/               Auth, Cart, Site
│   └── lib/                   API client, settings, formatting
└── docs/ARCHITECTURE.md
```
