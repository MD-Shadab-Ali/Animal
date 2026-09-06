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

### Scheduled work

Some things are not triggered by a request. Paying a booking in full checks the guest
in on the spot, but a stay settled while automatic check-in was switched off would
otherwise sit on "confirmed" forever, so a daily command sweeps those up:

| Command | When | What it does |
|---|---|---|
| `bookings:check-in-arrivals` | Daily, 01:00 | Checks in any booking that is paid in full but still confirmed |

Laravel runs these from a single cron entry. Without it the command never fires and
nothing tells you so — the bookings just stay where they are:

```bash
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Run it by hand any time:

```bash
php artisan bookings:check-in-arrivals
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
| **Homestay → Rooms** | Rooms let at the farm: name, type, sleeps, per-night price, extra-guest fee, minimum and maximum nights, photos, description |
| **Homestay → Bookings** | Stays with their status history, nights, guests, and what has been paid against what is owed |
| **Configuration → Homestay settings** | Whether rooms are offered at all, the rooms-page intro, check-in and check-out times, days of notice needed, how far ahead a room can be booked, house rules, cancellation note, and whether settling the balance checks the guest in |
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
  { "type": "why_choose_us",  "title": "Why buy from us", "config": { "items": [ ... ] } }
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

440 tests / 2381 assertions covering:

- every admin screen renders, and customers are refused entry to the panel
- all public API endpoints and their shapes
- registration issuing a Sanctum token
- the full purchase journey: cart → checkout → order → stock decrement → history
- cancelling an order restocking the goat
- coupon discounts, shop filters, and the contact form reaching the inbox
- booking a room: availability, nights and guest pricing, check-in, and cancellation

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
| POST | `/orders/{orderNumber}/payments` | Tell us you have paid, with a reference and a receipt |
| POST | `/orders/{orderNumber}/refunds` | Ask for money back on a cancelled order |
| GET | `/seller/earnings`, `/seller/payouts` | Statement and payout history |
| GET | `/seller/payout-methods` | Payment methods an admin opened for payouts |
| PUT | `/seller/payout-details` | Save where their earnings should be sent |
| POST | `/seller/payouts` | Request the balance they are owed |
| GET | `/sellers`, `/sellers/{slug}` | Public seller directory and profile |

**Homestay**

Browsing rooms needs no account. Holding one does — a room is booked in
somebody's name.

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| GET | `/rooms` | — | Rooms available, filtered by `check_in`, `check_out` and `guests` |
| GET | `/rooms/options` | — | Earliest and latest bookable dates, and the guest range to offer |
| GET | `/rooms/{slug}` | — | One room: pricing, capacity, photos, house rules |
| GET | `/rooms/{slug}/availability` | — | Which nights are already taken |
| POST | `/rooms/{slug}/bookings` | Bearer | Hold the room for those nights |
| GET | `/bookings` | Bearer | The customer's own stays |
| GET | `/bookings/{number}` | Bearer | One stay with its status history |
| POST | `/bookings/{number}/cancel` | Bearer | Cancel a stay that has not been checked out |
| POST | `/bookings/{number}/payments` | Bearer | Tell us you have paid, with a reference and a receipt |
| POST | `/bookings/{number}/pay/{gateway}` | Bearer | Start an online payment through eSewa or Khalti |
| POST | `/bookings/{number}/refunds` | Bearer | Ask for money back on a cancelled stay |

---

## Buying one goat vs the cart

**Buy now** on a goat page is a purchase of *that* goat. It adds the animal to the cart
and sends the buyer to `/checkout?buy=<id>`, where the summary, the totals and the order
itself are scoped to that one line — anything else in the cart stays there. Previously it
dropped the buyer on the whole-cart checkout, so someone with three goats already saved
bought all four.

`POST /checkout` takes an optional `goat_ids`. Absent, it means the whole cart, which is
the ordinary path from the cart page. Present, it must name goats actually in the cart, and
only those are ordered and removed.

Two rules keep it honest:

- **A coupon is redeemed by checking the cart out**, so it is ignored — and its box hidden —
  during a single-item purchase. Spending a whole-basket voucher on one animal, or showing
  a discount the server would not apply, are both worse than the rule.
- **A stale `?buy=`** — the goat since removed, or already bought in another tab — says so
  rather than offering an empty order with a live Place order button.

There is deliberately no "you are only buying one of these" banner. The buttons say which
route they take (**Check out this goat** vs **View cart (2)**), the summary lists exactly
what is being bought, and the header still shows the cart count — so explaining it again on
the checkout page is noise on the one screen that should carry none.

---

## Getting paid for an order

Money in is a **ledger**, not two columns on the order. `orders.paid_amount` and
`orders.payment_status` are derived from the confirmed rows in `payments` and are never
written by hand, so the order and the money can never drift apart.

```
buyer sees where to send it  →  buyer pays and says so  →  staff check the account
                             →  confirmed  →  order settles  →  closes itself
```

**Where the buyer sends it.** Each payment method has a payee block in **Configuration →
Payment methods**: account name, account or wallet number, bank, and an optional QR image.
Filling in the number is what turns on the "Pay for this order" panel on the buyer's order
page. Cash on delivery has no account, so it never offers one — the rider takes the cash
and staff record it.

**The buyer commits at checkout.** Once they pick a method they choose a plan, and the
order remembers it:

| Plan | Wanted up front | Rest |
|---|---|---|
| Pay in full now | The whole total | — |
| Pay an advance now | `advance_percent` of the total (30% by default) | On delivery |

**Money always comes in before the goat goes out.** A method that can take payment online
offers those two and nothing else — deferring the whole amount on such a method is just
cash on delivery under another name, and cash on delivery does not place orders.

A third plan, `on_delivery`, still exists but is reachable only through a method with *no*
payee account, where there is genuinely nowhere to send money in advance. That is what
keeps a bare install working.

**Cash on delivery is not a way to place an order.** It is how the balance is handed over
once the goat arrives, so it carries **Only for settling on delivery**: it appears at
checkout greyed out, explaining itself, and staff record the rider's cash against it later.
One guard makes that safe — if *nothing else* is active, a delivery-only method stands in
rather than leaving the shop unable to take an order, which is the state a fresh install
ships in.

**Setting the advance.** Each method's *Money up front* section takes an amount and says
how to read it — **a percentage of the order** or **a fixed amount**. Percentage is the
default, and is usually what you want in a shop whose goats run from 15,000 to 72,000: the
same flat 5,000 is a third of one animal and a fourteenth of another. Leave the amount
empty and the site-wide **Advance payment (%)** from Site settings applies. The form shows
what the setting comes to on a 50,000 order as you type, and an advance is always capped at
the order total.

**Each plan is asked for exactly what it promised.** *Pay in full* is chased until the
order is settled. *Advance now, rest on delivery* asks for the advance and then stops —
once it is in, the panel turns into a receipt telling the buyer to have the remainder ready
in cash, and only reopens if the order goes out for delivery with money still outstanding.
A submitted payment also hides the form until staff confirm or reject it, and the API
refuses a second open claim so a double submit cannot file the same payment twice.

**When the pay panel opens.** Immediately for the two prepay plans — the shop should not
hold an animal for someone who has put nothing down. A pay-on-delivery order is only asked
once it is **out for delivery**. An order on an advance is asked for the advance first and
the balance later, so `amount_due_now` is not always the full balance. Until an admin has
filled in a payee account for at least one active method the buyer is told we will call
them instead, and **Configuration → Payment methods** flags the methods still missing one.

**Submitting is a claim, not a receipt.** `POST /orders/{n}/payments` files the amount,
the transaction reference and an optional screenshot as `pending`. Nothing on the order
moves until staff confirm it against the real account, in **Sales → Payments** or on the
order's own Payments tab. Rejecting one puts the balance straight back.

Every row on that screen answers *who paid for which goat*: the payer and their phone, the
animals the order covers, the amount, the method and the transaction reference — no need
to open the order to find out what the money was for. Searching by a goat's name finds its
payments. Staff are emailed the same list when a claim comes in.

**Confirming money confirms the order.** Ticking a payment off in the panel is staff
saying it really arrived, so the order moves from `pending` to `confirmed` by itself rather
than asking them to say the same thing again in a second dropdown. It fires only once what
was promised up front is fully in — a part-paid advance leaves the order merely placed —
and only from `pending`, so it can never drag a later order backwards.

**Two rules the rest of the system leans on:**

1. **An order cannot be marked delivered until it is paid for.** Enforced on the model, so
   it holds for staff, for sellers and for any script — not just on one screen. This also
   protects the money going out: seller earnings settle on `delivered`, so a delivery
   recorded against an unpaid order would let a seller draw a payout for money the
   platform never received.
2. **Paying closes the order — when the payment *is* the delivery.** When the balance is settled on an order that is already
   `out_for_delivery`, it goes to `delivered` on its own. Deliberately only from that
   stage — paying for a goat still on the farm must not mark it delivered. Turn the
   behaviour off with **Mark orders delivered once paid** in Configuration → Site settings
   → Marketplace.

   This only ever fires for an order still owing money at the door — an advance plan, where
   the rider collecting the balance genuinely *is* the moment of delivery. **An order paid
   in full at checkout has no such signal**: the money arrived days earlier and says nothing
   about whether the goat reached the yard, so a person has to confirm it. That is not a gap
   to automate away — money cannot witness a delivery.

   The buyer closes it instead. A **"Yes, my goat arrived"** button appears on their order
   once it is out for delivery and fully paid — they are the one person with first-hand
   knowledge, which beats a rider's phone call relayed to staff. It is recorded against them
   in the order history ("Confirmed received by the customer"), because closing an order
   releases the seller's earnings and that is worth being able to point at later.

   The button is deliberately absent while money is still owed: those orders close themselves
   when the driver records the cash, so offering it would be redundant and would fail.

   Staff keep a fallback for buyers who never say anything: orders out for delivery and fully
   paid appear under **Sales → Orders → "To confirm delivered"** and are counted in the Orders
   navigation badge, so nothing sits forgotten holding up the farm's money.

### Cancelling

A buyer may cancel **at any point up to delivery** — pending, confirmed, preparing or out
for delivery. A goat is a large purchase and plans change, and until the animal is actually
with them there is something to call off. Once delivered there is not: that is a return,
which is a conversation rather than a button.

Cancelling puts the goats back on sale, cancels every line on the order, and — since it can
now land while a seller has the animal penned and ready to load — **tells any seller
involved**. If money had already been received it stays on the books as a refund owed.

### Refunds

A cancelled order does not un-charge itself. If the buyer had paid an advance, that money
is still ours to give back, and it stays on the books until it is.

```
buyer cancels  →  order page says what they paid  →  they ask for it back,
               →  saying where to send it  →  Sales → Refunds  →  staff send it
               →  mark refunded  →  the order's received total drops to zero
```

Refunds are rows in the same `payments` ledger with `type = refund`, so they simply
subtract — "what did we actually take" stays one sum. They carry their own destination
(`refund_to_*`), because the account money should go back to is not always the one it came
from: a wallet payment may need returning to a bank, and only the buyer can say.

**Sales → Refunds** is the screen for it: a *To send* tab with a red badge for anything
owed, the account to send to, the goats the order covered, and **Mark refunded** /
**Decline** actions. Marking one refunded drops the order's received total and sets its
payment status to `refunded`; declining leaves the money exactly where it was and lets the
buyer ask again. Staff can also raise one directly from an order's Payments tab with
**Kind: Refund** — useful for a partial refund such as a cancellation fee.

**Sales → Payments** now shows money coming in only; refunds have their own screen.

---

## Adding a payment gateway later

The checkout reads `payment_methods` from the database, so a new gateway is:

1. Enable the row in **Configuration → Payment methods** (eSewa, Khalti and bank
   transfer are already seeded, switched off) and put its keys in the `config` JSON
   column. Turn on **Available for seller payouts** too if money can also be sent
   out through it.
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
| Admin → Sales → Payments | Staff | Every rupee in — confirm or reject what buyers submit |
| Admin → Sales → Refunds | Staff | Every rupee back out — send what cancelled orders owe |
| Admin → Marketplace → Payouts | Staff | Mark paid or failed, including payouts sellers requested |
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

**Sellers ask to be paid themselves**, from `/seller/earnings`. They pick a method, save
the account it goes to, and request their balance; the request lands in **Admin →
Marketplace → Payouts** as `pending` for staff to send and mark paid.

The account it should go to is **copied onto the payout when it is raised**, the same way
commission is snapshotted onto an order line, and shown on the list, on the payout itself
and in the *Mark paid* dialog. That is deliberate rather than a live lookup: a seller who
changes their bank afterwards must not silently redirect money already queued, and once
sent, the payout is the record of where it went. The guards are the
same ones an admin settlement obeys — approved seller, delivered earnings, above the
minimum — plus one more: only one payout may be in flight at a time.

Which methods a seller may choose is admin-controlled. A payment method has two
independent switches in **Configuration → Payment methods**:

| Toggle | Means |
|---|---|
| Active at checkout | Buyers can pick it when they order |
| Available for seller payouts | Sellers can be paid out through it |
| Ask the seller for their bank name | The payout form also demands a bank name |

Cash on delivery is checkout-only; the wallets and bank transfer carry money both ways.

The third toggle exists because the two kinds of account are not the same shape. A wallet
number identifies the account by itself, so eSewa and Khalti ask only for the name on the
account and the number. A bank account number does not — it is meaningless without the
bank — so **Bank Transfer** ships with this on and the form asks for the bank as well,
marks it required, and refuses the save without it. Which methods behave this way is data,
not code: switch it on for a new bank added in the panel and the storefront form follows.
Until at least one method has the payout toggle on, the earnings page says payouts are
not open yet rather than offering a button that cannot work.

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
└── docs/
    ├── ARCHITECTURE.md        Stack, schema, order and booking lifecycles
    ├── BACKEND-STATUS.md      What is built, what is not
    ├── ADMIN-PANEL-FLOW.md    Admin panel screen by screen, and the buyer/seller flows
    ├── HOMESTAY-TUTORIAL.md   Take a stay from empty to checked out, start to finish
    └── HOMESTAY-HOWTO.md      Running the rooms: opening one, booking windows, refunds,
                               and bookings stuck on Confirmed
```
