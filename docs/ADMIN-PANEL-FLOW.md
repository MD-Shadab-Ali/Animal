# Admin panel — the complete working flow

How the admin panel actually works, and how every action in it lands on a buyer
or a seller. Written from the code, screen by screen and status by status.

Three surfaces, one database:

| Surface | Who | URL | Auth |
|---|---|---|---|
| Filament admin panel | Admin, Manager, Staff | `/admin` | Session (separate guard) |
| Storefront — buyer | Customers | `/`, `/shop`, `/cart`, `/checkout`, `/account/*` | Sanctum bearer token |
| Storefront — seller | Approved sellers | `/sell`, `/seller/*` | Sanctum bearer token + `seller` middleware |

Sellers and buyers **never** log into `/admin`. `User::canAccessPanel()` allows only
`admin`, `manager`, `staff`, and only while `is_active` is true.

---

## 1. Roles and what each one can open

Roles live on `users.role` (`App\Enums\UserRole`). Every Filament resource declares
an `$area` and the `RestrictsAccessByRole` trait hides it — from the sidebar *and*
from the URL — for roles that do not hold that area.

| Area | Admin | Manager | Staff | Customer |
|---|:--:|:--:|:--:|:--:|
| `catalog` — Goats, Categories | ✅ | ✅ | — | — |
| `sales` — Orders, Coupons, Payments, Refunds | ✅ | ✅ | ✅ | — |
| `customers` — Customers, Reviews | ✅ | ✅ | — | — |
| `marketplace` — Sellers, Payouts | ✅ | ✅ | — | — |
| `content` — Banners, Homepage sections, Pages, Menus, Testimonials, FAQs | ✅ | ✅ | — | — |
| `blog` — Posts, Post categories | ✅ | ✅ | — | — |
| `inbox` — Contact messages, Goat enquiries, Newsletter | ✅ | ✅ | ✅ | — |
| `configuration` — Site settings, Delivery zones, Payment methods | ✅ | — | — | — |

Small things that follow from this:

- **Only an admin can grant a role.** On the Customers form the `role` select is
  disabled for everyone else, and non-admins see only "Customer" in the options.
- The trait gates `canViewAny / canCreate / canEdit / canView / canDelete /
  canDeleteAny / shouldRegisterNavigation` — all from the same single check, so a
  hidden resource cannot be reached by typing its URL.
- Dashboard widgets carry their own `canView()`: Stats, Latest orders and Sales
  chart need `sales`; Top selling goats needs `catalog`. A staff member sees three
  widgets, not four.
- `is_active = false` blocks sign-in *and* every area check — deactivating a staff
  account is instant, no role change needed.

**Panel chrome** (`AdminPanelProvider`): brand "Goat Haven Admin", primary green /
gray slate, sidebar collapsible on desktop, full-width content, login + profile
pages enabled. Navigation groups in fixed order: Catalog, Sales, Customers,
Marketplace, Storefront, Blog, Inbox, Configuration (Configuration ships collapsed).

---

## 2. The sidebar, exactly as it renders

| Group | Screen | Nav badge |
|---|---|---|
| **Catalog** | Goats | Pending listings (warning) — else live count (gray) |
| | Categories | — |
| **Sales** | Orders | — (tabs carry the counts) |
| | Coupons | — |
| | Payments | — |
| | Refunds | — |
| **Customers** | Customers | — |
| | Reviews | — |
| **Marketplace** | Sellers | Applications awaiting review (warning) |
| | Payouts | — |
| **Storefront** | Banners · Homepage sections · Pages · Menus · Testimonials · FAQs | — |
| **Blog** | Posts · Post categories | — |
| **Inbox** | Contact messages | Unread count (warning) |
| | Goat enquiries | `status = new` count (warning) |
| | Newsletter | — |
| **Configuration** | Site settings · Delivery zones · Payment methods | — |

Global search covers goats by `name`, `sku` and `breed`.

---

## 3. Dashboard

Four stat tiles (`StatsOverview`), all currency-aware via `Setting::currencySymbol()`:

1. **Revenue** — sum of `orders.total` excluding cancelled, with month-to-date underneath.
2. **Orders** — total count, description "N waiting to be confirmed" (turns warning when N > 0).
3. **Goats in the shop** — `Goat::published()->inStock()` count, with the sold count underneath.
4. **Customers** — count of `role = customer`, with unread contact messages underneath.

Then:

- **Latest orders** — last 10 orders, with a View action straight to the order.
- **Sales chart** — revenue (left axis) and order count (right axis), filterable to
  7 / 30 / 90 / 365 days. Buckets are pre-filled so quiet days render as zero, and
  it switches from daily to monthly buckets past 90 days.
- **Top selling goats** — top 5 by revenue from `order_items`, joined against
  non-cancelled, non-trashed orders. Grouped on the **snapshot** name and SKU, so a
  renamed or deleted goat still reports correctly.

---

## 4. The buyer's journey, and where staff meet it

```
register → browse → cart → checkout → order placed → (pay) → confirmed
    → processing → out_for_delivery → delivered → review
                    ↘ cancelled (any time before delivered) → refund
```

### 4.1 Account
- `POST /auth/register` / `/auth/login` return a Sanctum token. Rate limited:
  5/min per email plus 20/min per IP.
- Forgot password → `POST /auth/forgot-password` sends a link **pointing at the
  storefront**, not the API. Unknown addresses get an identical response so the
  endpoint cannot enumerate accounts. A successful reset revokes every token.
- Changing the password from `/auth/password` revokes all *other* tokens.
- Staff see the account under **Customers**. Editing a customer with the password
  field left blank does **not** reset their password (`dehydrated` only when filled).

### 4.2 Browsing
Only goats matching `Goat::published()` are ever visible:

```
status = 'published'  AND  approval_status = 'approved'
AND ( seller_id IS NULL  OR  seller.status = 'approved' )
```

That third clause is the whole vetting model in one line — **suspending a seller
removes their entire catalogue from the shop instantly**, with no batch job.

Detail pages increment `goats.views` on every request (no bot filtering — a known gap).

### 4.3 Cart and coupon
Persistent cart per account (`carts` / `cart_items`), one optional coupon on the cart.
Weight-priced listings hold a `weight_kg` per cart line, so the same animal can sit on
several lines at different weights.

### 4.4 Checkout — `CheckoutService::place()`
Everything below happens inside **one DB transaction** with `lockForUpdate()` on the
goat rows, so two buyers cannot claim the same animal:

1. Cart must exist and be non-empty.
2. Scope: `cart_item_ids` wins over `goat_ids` — "Buy now" buys that line, not the
   whole cart, and buying the 25 kg line does not drag the 37 kg line along.
3. Payment method must be **active** and `isCheckoutSelectable()`. A delivery-only
   method (Cash on Delivery) cannot *place* an order — unless nothing else is switched
   on, in which case it stands in so the shop is never un-orderable.
4. Payment plan must be one the method offers (`full` / `advance` / `on_delivery`).
   Unspecified falls back to `on_delivery` where allowed.
5. Delivery zone must be active.
6. Per line: goat must still be `published`; the combined claim across lines must not
   exceed stock; the weight must still be inside the seller's range (re-checked
   because the seller may have narrowed it while the cart sat).
7. Unit price recomputed server-side — `priceForWeight()` for weight-priced listings,
   `effective_price` otherwise. **Client totals are never trusted.**
8. `min_order_amount` enforced.
9. Coupon applied **only on a whole-cart checkout**, and re-checked with
   `isRedeemable()` (active, in date, global cap, per-user cap, minimum order).
10. Delivery charge from the zone, honouring its free-above threshold.
11. Order written with a **full snapshot**: customer name/phone/email, address,
    `delivery_estimate`, `currency`, payment method + plan + `advance_required`.
12. Each line written with its own snapshot: goat name, SKU, thumbnail, weight,
    price per kg, unit price, plus `seller_id`, `seller_name`, `commission_rate`,
    `commission_amount`, `seller_earning` — **frozen at purchase**.
13. Stock decremented; a goat hitting 0 flips to `status = sold`; anything at or
    below `low_stock_threshold` is queued for a staff alert.
14. If exactly one seller supplied everything, `delivery_seller_id` and
    `delivery_earning` are set — the delivery charge is theirs. **No commission is
    taken on delivery.**
15. Coupon `used_count` incremented; only the purchased lines leave the cart; the
    cart's coupon clears once it is empty.

Order number format: `GH-yymmdd-XXXXX`, uniqueness-checked in a loop.

Notifications fire **after the transaction commits**, so a rolled-back order never
emails anyone: buyer confirmation, staff new-order alert, staff low-stock alerts,
and one sale notification per seller covering only their own lines.

### 4.5 Paying
Money is a **ledger** (`payments`), never a column written by hand. `orders.paid_amount`
and `orders.payment_status` are always re-derived from confirmed rows by
`PaymentService::sync()`.

| Path | Lands as | Moves the order? |
|---|---|---|
| Buyer submits "I have paid" (`POST /orders/{n}/payments`) | `pending`, `source = customer`, optional receipt file | No — it is a claim |
| Staff record cash/transfer (order row action or Payments tab) | `confirmed`, `source = staff` | Yes, immediately |
| Staff confirm a buyer's claim | `confirmed` | Yes |
| Staff reject a claim | `rejected` | Re-syncs (removes it) |

Guards on a buyer claim: order not cancelled, not already fully paid, and **only one
open claim at a time** (a hidden form is not a guard — a double submit would otherwise
file two identical rows and staff would have to work out which is real).

What `sync()` then does, in order:

1. `paid_amount` = sum of `signed_amount` over confirmed rows (refunds are negative).
2. `payment_status` = `refunded` if it netted to zero *and* a confirmed refund exists;
   else `unpaid` / `partially_paid` / `paid` (with a 1-cent tolerance on "paid").
3. **`advanceOnPayment()`** — an order still at `pending` with money in and no
   outstanding advance moves itself to `confirmed`. Staff confirming a payment *is*
   confirming the order; they should not have to say it twice. Only from `pending`,
   so it never drags an order backwards.
4. **`closeIfSettled()`** — an order already `out_for_delivery` that becomes fully
   paid moves itself to `delivered`. Gated on the `auto_deliver_on_payment` setting.
   Only from `out_for_delivery`: paying for a goat still on the farm does not deliver
   it, and marking it delivered would release the seller's earnings for an animal the
   buyer has not seen.

### 4.6 Fulfilment, and the two directions status travels
Order flow is **forward only**: `pending → confirmed → processing → out_for_delivery
→ delivered`, with `cancelled` reachable from anything before `delivered`.

Line flow is also forward only: `pending → preparing → ready → handed_over`, plus
`cancelled`.

They are kept in step in both directions:

- **Lines roll the order up** (`SellerFulfilmentService::syncOrderStatusFromLines`).
  The order can only be as far along as its **slowest** live line. All lines at
  `preparing`/`ready` → order `processing`. All at `handed_over` → `out_for_delivery`.
  Applies to **every** order, seller-run included.
- **The order carries down to lines** (`OrderObserver::carryStatusDownToLines`).
  Moving the order drags any lagging line forward to the matching line state
  (`Order::lineStatusFor()`: `processing → preparing`, and both `out_for_delivery`
  and `delivered → handed_over`).
- Both directions are forward-only, and line writes do not re-trigger the roll-up,
  so there is no loop and a manual staff decision is never rewound.

### 4.7 The delivered gate
`OrderObserver::updating()` throws if an order is moved to `delivered` while
`canBeDelivered()` is false — i.e. while it is not fully paid. It sits on the model,
not on one screen, so it holds for staff, for the seller, and for the API alike.
Cash on delivery is no exception: the rider's cash is recorded as a payment, and
*that* is what closes the order.

Consequently the "Delivered" option is **removed from the dropdown** (both the row
action and the edit form) on any unpaid order, with helper text saying why.

### 4.8 Buyer confirms receipt
`POST /orders/{n}/received` — available only when the order is `out_for_delivery`
**and** fully paid. It writes the status change with the note *"Confirmed received
by the customer."*, so the history distinguishes it from staff clicking the same
button. It matters because it releases the seller's earnings.

The Orders list carries a dedicated **"To confirm delivered"** tab for exactly these:
paid, out with the rider, and nothing will close them on its own — money cannot
witness a delivery.

### 4.9 Cancelling
The buyer may cancel right up to the handover (`isCancellable()`: not `delivered`,
not `cancelled`). Staff can cancel from the order row action at any point before
delivery, with a required reason — kept available even on seller-run orders, because
without it a disputed or fraudulent order could never be resolved.

`OrderObserver` on cancel:
1. Stamps `cancelled_at`.
2. **Restocks** every tracked goat and flips `sold` back to `published`.
3. Cancels every non-cancelled order line, so no seller is left preparing an animal
   for a sale that is off.
4. Emails each affected seller (`SellerOrderCancelledNotification`).
5. Emails the buyer the status change.

### 4.10 Refunds
Only a cancelled order is refundable, and only for `refundable_amount` = whatever
`paid_amount` still shows (already net of refunds paid out).

- Buyer files `POST /orders/{n}/refunds` with a destination. The method must be a
  **payout-capable** rail, and a bank transfer is not a destination without the bank
  name — the same rule seller payout details obey.
- It lands as a `refund` row at `pending`. One open request at a time.
- Staff work it on **Sales → Refunds**: a "Send to" column with account, name and bank;
  **Mark refunded** (records the transaction reference and confirms it, which
  re-syncs the order) or **Decline** with a required reason.
- A confirmed refund subtracts from `paid_amount`; netting to zero sets
  `payment_status = refunded`.

### 4.11 Reviews
Customers review a delivered goat. Reviews land unapproved. Staff moderate on
**Customers → Reviews**: Publish, Hide, bulk Publish selected, plus soft-delete /
restore / force-delete. Only `is_approved` reviews reach the storefront and the
rating average.

---

## 5. The seller's journey, and where staff meet it

```
apply → pending → approved → create listing (draft) → submit → pending review
   ↘ rejected                        ↘ rejected (reason) → edit → resubmit
   ↘ suspended (catalogue hidden)     ↘ approved → live in shop → sold
                                          → fulfil line → delivered → earnings → payout
```

### 5.1 Applying — `POST /seller/apply`
- Blocked entirely if `marketplace_enabled` or `seller_applications_open` is off.
- One application per account, enforced by querying `sellers.user_id` (unique index).
- Required: farm name, contact phone, city, **national ID number**, **ID document**
  (jpg/png/webp/pdf, ≤5 MB). Trade licence optional — plenty of smallholders have none.
  Optional: bio, contact email, address line, area, postal code.
- Documents are stored on the public disk under `sellers/documents`; replacing one
  deletes the file it replaces.
- Lands as `status = pending`. Staff are emailed `NewSellerApplicationNotification`
  with a direct link to the record.

**Re-applying after staff deleted an application:** only a *live* application blocks a
new one. A resubmission **revives the archived row** rather than inserting a second
(the unique index would reject it, and past `order_items` still reference that id).
The revived row resets to `pending` with the old review note, approval timestamp and
approver cleared, and the slug regenerated (ignoring itself, so the same farm name
does not drift to `farm-name-2`). **Previously approved, unsold listings drop back to
`pending`** — a re-approved seller must not silently resurrect listings cleared under
the old application. Sold goats are left alone as history.

### 5.2 Vetting — Marketplace → Sellers
Columns: farm name (with the account email underneath), city, phone (copyable),
listings count, commission (effective rate, marked "default" or "custom"), **Owed**
(unpaid earnings, warning-coloured when > 0), **ID** (a document-check icon —
green with "ID document on file", red with "No ID document — do not approve"),
status badge, applied-ago.

Filters: Trashed, and status (Pending review / Approved / Suspended / Rejected).

Row actions:

| Action | Effect |
|---|---|
| **Approve** | `status = approved`, stamps `approved_at` + `approved_by`. Emails the seller with their commission rate and a dashboard link. Their listings still get reviewed one by one. |
| **Reject / Suspend** | Label flips based on current status. Requires a reason, which is included in the email. Suspending pulls the whole catalogue out of the shop instantly via the `published()` scope. |
| **Pay out** | Visible only when unpaid earnings > 0. Settles them into a new payout. Validation failures (below the minimum, nothing to settle) surface as a red notification rather than an exception. |
| **Restore** | Brings a deleted application back, still needing review. |
| **Delete permanently** | Removes it for good. The person can then apply again from scratch. |
| **Edit** | Full form. |

The form has five sections: **Vetting** (status, commission override with the site
default as placeholder, note to the seller), **Farm** (account — locked on edit —
farm name, slug, phone, email, bio), **Location**, **Identity documents** (national
ID, ID document and trade licence, both openable and downloadable in place), and
**Payout details** (collapsed; the method list is whatever Configuration → Payment
methods marks payout-capable, with the bank-name field appearing only for methods
that set `requires_bank_name`).

> **Do not add the identity or payout fields to `$hidden` on the Seller model.**
> `$hidden` strips them from `attributesToArray()`, which is what Filament fills forms
> from — it silently blanks the national ID, both documents and the payout account
> number in the panel while the data sits correctly in the database. Exposure is
> controlled in the API resources instead: `SellerResource` (public) omits them,
> `SellerProfileResource` (owner) masks the account number.

### 5.3 Listings — the seller side
- `POST /seller/listings` creates a **draft** (`status = draft`, `approval_status = draft`).
- Fields a seller controls: category, name, breed, age (months), weight (kg), gender,
  colour, teeth, health status, vaccinated, price, sale price (must be `< price`),
  stock, weight range (`min_weight_kg` ≤ advertised weight ≤ `max_weight_kg`),
  weight step, short description, description, video URL, and free-form spec rows.
- Photos: up to **8** per listing, jpg/png/webp ≤5 MB each. The first photo becomes
  the listing thumbnail — which is what the shop grid and every order line show.
  Deleting the thumbnail hands the role to the next photo.
- `POST /seller/listings/{id}/submit` sets `approval_status = pending`,
  `status = published`, stamps `submitted_at` and clears any rejection reason.
- **An approved or pending listing is locked against editing** (`assertEditable`) —
  only `draft` and `rejected` are editable — so a seller cannot swap the animal after
  moderation. Photo upload and deletion are gated on the same rule.
- A listing that has ever been ordered cannot be deleted.
- A seller can only ever read or write their own listings (`assertOwns`, 403 otherwise).

### 5.4 Moderation — Catalog → Goats
The Goats table is shared by house stock and seller stock. Seller-relevant columns:
**Seller** (blank shows "House stock" in gray) and **Review** (`draft` / `pending` /
`approved` / `rejected` badge). Filters include review status and seller.

| Action | Effect |
|---|---|
| **Approve** (visible only while `pending`) | `approval_status = approved`, `status = published`, clears the rejection reason, stamps `approved_at` + `approved_by`. Emails the seller "your listing is live" with a storefront link. Goes live immediately. |
| **Reject** (visible only while `pending`) | Requires "What needs changing?", sets `approval_status = rejected`, `status = draft`. Emails the seller the reason so they can fix and resubmit. |

Other row actions: Edit, **Duplicate** (replicates while excluding `slug`, `sku` and
`views`), Delete. Bulk: delete, force delete, restore.

Full goat form — five tabs: **Details** (name, slug, category with inline create,
seller, SKU, short description, rich description), **Livestock** (breed, gender,
colour, age, weight, teeth; health status and vaccinated; a repeater for arbitrary
spec rows), **Pricing & stock** (price, sale price, weight range + step with a derived
per-kg rate placeholder, track stock, stock), **Media** (thumbnail, video URL),
**Publishing** (status, sort order, approval status, rejection reason, featured, meta
title and description). A drag-reorderable gallery relation manager sits alongside.

Table extras: thumbnail, name with SKU underneath, category, breed, weight, age,
gender, price (with "On sale:" underneath), **stock badge** (gray `∞` when untracked,
red at 0, warning at or below `low_stock_threshold`, else green), status badge, an
inline **Featured** toggle, vaccinated icon, views, created date. Filters: category,
status, review status, seller, gender, featured, in/out of stock, trashed.

### 5.5 Who runs an order
Decided by **who supplied the goods**, not by role. `Order::soleSellerId()` queries
`order_items` directly rather than reading the loaded relation — the seller's own
order list eager-loads `items` filtered to that seller, and judging ownership from a
filtered set would mark every mixed order seller-managed.

| Order contains | Runs it | Staff can |
|---|---|---|
| Goats from **one seller only** | That seller, `pending → … → delivered` | Everything, including cancel |
| Any **house stock** | Staff | Everything |
| Goats from **two or more sellers** | Staff — no single seller speaks for it | Everything |

On the admin side this is surfaced as a **"Run by"** column (the seller's farm name
with a "seller-supplied" description, or "Our team" in gray), and the order form's
status helper text spells out that both sides can move a seller-run order — the last
change wins, and every one lands in the history.

Sellers move **forward only** and **cannot cancel** — they are told to contact staff.
Payment status is never seller-editable.

### 5.6 Seller fulfilment
- `PUT /seller/order-items/{item}/status` moves **one of their own lines**, forward
  only, never to `cancelled`, and never on a cancelled order.
- Marking a line **ready** emails staff `SellerReadyForCollectionNotification` with
  the goat, the seller's farm name, phone and pick-up address, the order number and
  destination city, and any note the seller attached.
- `PUT /seller/orders/{number}/status` moves the **whole order**, but only on an order
  they supplied in full. It also drags their own lines into step, and appends
  `Seller: <note>` to the order's internal note.
- Staff can override any line from the order's **"Goats in this order"** relation
  manager via **Set progress** — staff can set it to anything, including `cancelled`;
  the seller can only move it forward. That override runs the same roll-up, so the
  buyer's order status keeps pace.
- **The buyer's phone number is withheld from the seller** until the order leaves
  `pending`. While `pending` or `cancelled`, a seller sees only the buyer's name and
  city; afterwards they also get phone and area.

### 5.7 Earnings and payouts
Money definitions, and they are deliberately distinct:

| Figure | Means |
|---|---|
| `pending_earnings` | Sold but **not yet delivered** — not earned |
| `lifetime_earnings` | Earned across **delivered** orders only |
| `unpaid_earnings` | Delivered **and** not yet stamped into a payout |
| `paid` | Sum of payouts at `status = paid` |

Each of those counts goat lines **plus** delivery earnings on orders the seller
delivered themselves.

> The bug worth remembering: `lifetime_earnings` used to sum every non-cancelled
> sale, so an undelivered, unpaid order read as money in hand. It now counts
> delivered orders only, with `pending_earnings` holding the in-flight money.

**Delivery attribution:** one seller supplying the whole order earns the delivery
charge; a house-stock or multi-seller order gives it to the platform, since staff
arrange transport. It is stored on `orders.delivery_seller_id` + `delivery_earning`
and deliberately kept **off** `order_items.seller_earning`, so a line's earning is
always its own total minus its own commission and stays auditable. On the seller's
earnings statement it is credited to the **first line of that order only** — delivery
is earned once per order, and a seller with two goats on one order would otherwise
see it twice.

**Requesting a payout** (`POST /seller/payouts`) — guarded on: approved account,
payout details on file (method + account name + account number, plus bank name where
the method demands it), the saved method still being a live payout rail, and no
payout already `pending`/`processing`. The earnings page also returns a
`blocked_reason` in plain words so the storefront never has to guess why the button
is off.

**Settling** (`PayoutService::settle`, from either the seller's request or the Sellers
row action) runs in a transaction: locks the unpaid delivered lines and the unpaid
delivered delivery charges, refuses if there is nothing or if the total is below
`min_payout_amount`, creates the payout with a `PO-yymmdd-XXXXX` reference, and
**stamps `payout_id` onto the lines and `delivery_payout_id` onto the orders** — so
the same earning can never be paid twice.

The payout **snapshots** the destination (method, bank, account name, account number)
rather than looking it up later: staff pay against what the seller asked for, and a
seller changing their bank afterwards must not silently redirect a payout already in
the queue.

**Marketplace → Payouts** shows reference, seller, sales count, amount, "Send via"
(method name with the bank underneath), account (copyable, with the account name
underneath), status, paid date, created-ago. Filters: status, seller.

| Action | Effect |
|---|---|
| **Mark paid** | Shows the destination in the modal *before* confirming, takes an optional transaction reference, stamps `paid_at`. |
| **Mark failed** | Releases the earnings — `payout_id` and `delivery_payout_id` are nulled — so they go straight back into the seller's unpaid balance and can be settled again. |

The Payouts edit form is mostly read-only by design: reference and amount are locked,
the destination block is locked (editing it here would not change where the seller
wants their money, and the record should keep saying where *this* payout went), and
the status select carries a note steering staff to the list actions instead, because
those also release or settle the earnings.

---

## 6. Orders screen, in full

### Tabs
| Tab | Query | Badge |
|---|---|---|
| New | `status = pending` | count, warning |
| To confirm delivered | `out_for_delivery` AND `paid_amount >= total` | count, info |
| In progress | `confirmed`, `processing`, `out_for_delivery` | — |
| All *(default)* | everything | — |

### Columns
Order number (searchable, sortable, copyable) · Customer (with phone underneath) ·
Items count badge · City · Total · Payment method (uppercased badge) · Plan
(Paid up front / Advance / On delivery) · Payment status (red unpaid, amber partially
paid, green paid, gray refunded) · Status (colour from `Order::STATUS_COLORS`) ·
Run by · Placed. Default sort: newest first.

### Filters
Trashed · Status (multi-select) · Payment status · Delivery zone · Placed today.

### Row actions
| Action | Notes |
|---|---|
| **View** | Infolist: Order / Customer / Payment cards, delivery address, totals, collapsed internal note. |
| **Update status** | Status select (Delivered removed while unpaid, with helper text), an optional note **the buyer sees on their order**, and — only when "Preparing" is picked — a **photo of the animal**. |
| **Record payment** | Hidden once paid or cancelled. Defaults to the outstanding balance, takes an optional reference, and goes **through the ledger**, never straight onto the order. |
| **Cancel order** | Hidden once delivered or cancelled. Requires a reason. Warns that the goats go back on sale and the seller is told. |
| **Edit** | See below. |
| **Restore / Force delete** | On trashed orders. |

**The status photo** is the detail worth knowing: it is only offered on the
`processing` step, because that is where the buyer loses sight of what they bought —
the listing photo was taken before they ordered, and on a weight-priced listing it
may not even be the animal they are getting. It is guarded as well as hidden, so a
photo picked and then abandoned when the status changed cannot ride along on the
wrong step. It is stored on the **status-history row** (`order_status_histories.photo`),
not on the order, because one order moves several times and each move has its own
evidence.

The note and the photo are handed to the observer through the transient
`$order->statusNote` / `$order->statusPhoto` properties — they are not columns, they
belong on the history row. The note is *also* appended to `admin_note` as the running
internal note.

### Edit form
- **Create is a different screen entirely** — see §7.
- Fulfilment section: status select (same delivered gate), read-only payment status
  ("Worked out from the payments below"), read-only plan agreed at checkout,
  read-only "Wanted up front" with a live explanation (nothing / still outstanding /
  received), read-only amount received with the outstanding balance underneath, and
  the internal note.
- Summary section (all read-only): order number, payment method, subtotal, discount,
  delivery charge, total.
- Delivery address section (editable, collapsible): name, phone, email, address line,
  area, city, postal code, delivery zone, customer note.

### Relation managers
1. **Goats in this order** — thumbnail, goat name (linking to the goat record) with
   SKU, unit price, qty, line total, seller (or "House stock"), weight (`—` when the
   listing is sold at a single weight), and **Supplier progress** with the seller's
   note underneath. Row action: **Set progress**.
2. **Payments** — reference, amount (refunds flagged and red), via, transaction
   reference, **Receipt** badge linking straight to the uploaded proof, status,
   source (From customer / Recorded by staff), added-ago. Header action **Record a
   payment**; row actions **Confirm** (only while pending) and **Reject**.
3. **Status history** — when, from, to, by (blank shows "Customer"), note. Newest
   first, unpaginated. Written by the observer on **every** status change, including
   the creation row (`Order placed`).

---

## 7. Staff-created orders — "New phone order"

Orders are never a plain insert. `CreateOrder::handleRecordCreation()` hands off to
`ManualOrderService`, which gives the same guarantees as the storefront:
row locking, server-side prices, stock decrement, price snapshots, and commission
frozen onto each line.

The create form shows only the create-time sections:
- **Customer** — pick a customer account (searchable by name, email, phone). Choosing
  one **auto-fills** name, phone, email and their default address. Plus payment method.
- **Goats** — a repeater: goat (published or draft), quantity, and a **unit price
  override for a haggled price**.
- **Charges** — delivery zone, delivery charge (blank uses the zone rate), discount.
- **Delivery address** — same fields as the edit screen.

On success it redirects straight to the order's View page with "Order created and
stock updated".

---

## 8. Money — the complete picture

### Payment statuses
`unpaid` → `partially_paid` → `paid`, plus `refunded`. Never set by hand; always
derived by `PaymentService::sync()`.

### Payment row statuses
`pending` → `confirmed` / `rejected`. Types: `payment` and `refund` (refunds carry a
negative `signed_amount`).

### Payment plans
| Plan | `advance_required` | Behaviour |
|---|---|---|
| `full` | the whole total | Buyer pays up front |
| `advance` | `method->advanceFor(total)` | Advance now, rest on delivery |
| `on_delivery` | `null` | Nothing wanted until the door |

`advanceFor()` uses the method's own fixed amount or percentage if set, otherwise the
site-wide `advance_percent`, clamped between 0 and the order total.

`Order::amount_due_now` is the advance while it is outstanding, and the full balance
after — so the buyer is never asked for more than is actually due yet.

### Payment methods — Configuration → Payment methods
Per method: code, name, instructions, logo, sort order, and the switches that decide
everything above:

| Switch | Meaning |
|---|---|
| `is_active` | Available at all |
| `on_delivery_only` | Shown at checkout but greyed out — cannot *place* an order, only settle one. This is what Cash on Delivery is. |
| `supports_payout` | Can be a rail for seller payouts **and** buyer refunds |
| `requires_bank_name` | An account number alone is not enough — ask for the bank |
| `requires_advance` | Money is wanted up front |
| `advance_type` / `advance_amount` | Fixed or percentage; blank uses the site default |
| `payee_account_name` / `payee_account_number` / `payee_bank_name` / `payee_qr_image` | **Filling the account number or QR in is what turns on the buyer's "pay now" panel** (`isPrepayable()`) |
| `refund_eta` | Completes "Refunds by this method usually arrive ___" |
| `config` (KeyValue) | Gateway credentials — `$hidden`, never exposed through the API |

`paymentPlans()` follows from those: a non-prepayable method offers `['advance']` if
it insists on money up front (the obligation stands even though there is nowhere
online to send it — silently downgrading would throw the setting away), otherwise
`['on_delivery']`. A prepayable method offers `['full', 'advance']` and nothing else —
deferring the whole amount on a method that *can* take it online is just cash on
delivery wearing another name.

### Payments screen — Sales → Payments
Every movement of money in one place: reference, order (linked), paid by (with the
order phone underneath, "Walk-in" when absent), **For** (a goats summary, searchable
against `order_items.goat_name`), amount with a **"Received" running total summarizer**
over confirmed payments only, via, transaction reference, receipt badge, status,
source, paid date, logged-ago. Filters: status, method, received today.

The **Confirm** modal renders the uploaded receipt **inline** — the evidence sits
right where the decision is made — or says "No receipt was attached — check the
account before confirming."

---

## 9. Every status machine on one page

| Machine | Values | Rules |
|---|---|---|
| Order status | `pending`, `confirmed`, `processing`, `out_for_delivery`, `delivered`, `cancelled` | Forward-only along `FLOW`; `cancelled` from anything pre-delivery; `delivered` requires fully paid |
| Order line fulfilment | `pending`, `preparing`, `ready`, `handed_over`, `cancelled` | Sellers forward-only along `SELLER_FLOW`, never to `cancelled`; staff may set anything |
| Order payment status | `unpaid`, `partially_paid`, `paid`, `refunded` | Derived only |
| Payment row | `pending`, `confirmed`, `rejected` | Buyer claims start pending; staff records start confirmed |
| Listing approval | `draft`, `pending`, `approved`, `rejected` | Editable only while `draft` or `rejected` |
| Listing status | `draft`, `published`, `sold`, `archived` | `sold` set automatically at zero stock; restored on cancel |
| Seller | `pending`, `approved`, `suspended`, `rejected` | Only `approved` may list; anything else pulls the catalogue |
| Payout | `pending`, `processing`, `paid`, `failed` | `failed` releases the earnings back to unpaid |

---

## 10. Inbox and content screens

### Inbox
- **Contact messages** — unread badge. Actions: **Log reply** ("What did you tell
  them?", stored in `admin_reply`), **Mark read / Mark unread** (label flips), delete;
  bulk **Mark as read**. Fields: name, email, phone, subject, message, is_read,
  admin_reply.
- **Goat enquiries** — `status = new` badge. Tied to a specific goat ("About"
  column). Actions: **Mark contacted**, **Close**, delete.
- **Newsletter** — email + active toggle.

### Storefront
| Screen | Fields |
|---|---|
| Banners | placement, sort order, title, subtitle, description, image, mobile image, button text + link, text align, overlay colour, starts/ends at, active |
| Homepage sections | type, sort order, title, subtitle, description, background colour, active, **config JSON** (validated), custom HTML |
| Pages | title, slug, excerpt, rich body, banner image, sort order, active, show in footer, meta title + description |
| Menus | name, slug + a drag-reorderable item manager with parent nesting |
| Testimonials | name, designation, avatar, quote, rating, active, sort order |
| FAQs | group, question, rich answer, active, sort order |

### Blog
| Screen | Fields |
|---|---|
| Posts | title, slug, category, author, excerpt, rich body, cover image, published at, is published, is featured, meta title + description |
| Post categories | name, slug, active |

### Catalog — Categories
name, slug, parent (self-nesting), sort order, description, image, icon, active,
featured, meta title + description. Soft-deleted rows stay reachable via a Trashed filter.

### Sales — Coupons
code, description, type (percent / fixed), value, max discount, min order amount,
usage limit, usage limit per user, used count, starts at, expires at, active.
`Coupon::isRedeemable()` checks all of it plus the per-user cap at checkout.

### Configuration — Delivery zones
name, estimated time, description, charge, free-above threshold, sort order, active.
The estimate is **copied onto the order** at checkout so the promise survives the zone
being edited or deleted later.

---

## 11. Site settings — Configuration → Site settings

A hand-built page, not a resource. It reads every row of `settings`, groups them, and
renders the right input from the declared `type` (`text`, `textarea`, `boolean`,
`number`, `color`, `image`). **A settings row added to the database later appears
automatically**, and an unrecognised group gets its own tab from its headline.

Tabs, in order: General · Contact · Social links · Shop · Marketplace · Appearance ·
SEO & tracking.

| Group | Keys |
|---|---|
| General | `site_name`, `site_tagline`, `site_logo`, `footer_logo`, `site_favicon`, `footer_about`, `copyright_text` |
| Contact | `contact_phone`, `contact_email`, `contact_address`, `whatsapp_number`, `business_hours`, `google_map_embed` |
| Social | `facebook_url`, `instagram_url`, `youtube_url`, `twitter_url`, `tiktok_url` |
| Shop | `currency_code`, `currency_symbol`, `number_locale`, `currency_position`, `min_order_amount`, `enable_coupons`, `enable_reviews`, `enable_wishlist`, `low_stock_threshold`, `goats_per_page` |
| Marketplace | `marketplace_enabled`, `default_commission_rate`, `seller_applications_open`, `min_payout_amount`, `advance_percent`, `auto_deliver_on_payment`, `seller_terms` |
| Appearance | `primary_color`, `secondary_color`, `accent_color`, `announcement_enabled`, `announcement_text`, `announcement_link` |
| SEO | `meta_title`, `meta_description`, `meta_keywords`, `og_image`, `google_analytics_id`, `facebook_pixel_id` |

Settings are cached with `rememberForever` and busted automatically on save.

**Never hardcode currency.** `Setting::currencyCode()`, `Setting::currencySymbol()`
and `Setting::numberLocale()` are the only places a fallback lives.

The marketplace switches are the big levers:
- `marketplace_enabled = 0` → the shop runs as a single farm again; no new applications.
- `seller_applications_open = 0` → existing sellers carry on, no new ones.
- `auto_deliver_on_payment = 0` → an out-for-delivery order stops closing itself on
  final payment, and staff must click Delivered.
- `default_commission_rate` → the rate for any seller without an override. Changing it
  never moves a past sale, because commission is frozen per line at purchase.

---

## 12. Who gets told what

| Notification | To | Trigger |
|---|---|---|
| `OrderPlacedNotification` | Buyer | Checkout commits |
| `NewOrderNotification` | All active staff | Checkout commits |
| `LowStockNotification` | All active staff | An order pushes a goat to or below `low_stock_threshold` |
| `SellerSaleNotification` | Each seller, own lines only | Checkout commits |
| `OrderStatusChangedNotification` | Buyer | **Every** status change |
| `SellerOrderCancelledNotification` | Each affected seller | Order cancelled |
| `SellerReadyForCollectionNotification` | All active staff | A seller marks a line `ready` |
| `PaymentSubmittedNotification` | All active staff | Buyer files a payment claim |
| `PaymentReceivedNotification` | Buyer | Staff confirm a payment |
| `RefundRequestedNotification` | All active staff | Buyer requests a refund |
| `RefundSentNotification` | Buyer | Staff confirm the refund |
| `NewSellerApplicationNotification` | All active staff | Seller applies (or re-applies) |
| `SellerApplicationReviewed` | Seller | Approved / rejected / suspended |
| `ListingReviewed` | Seller | Listing approved / changes requested |
| `ResetPasswordNotification` | Anyone | Forgot password — links to the **storefront** |

`User::staffRecipients()` = every active `admin`, `manager` or `staff` account.
`NewOrderNotification` also lands in-panel, not just by mail.

---

## 13. Rate limits

| Limiter | Applies to | Rate |
|---|---|---|
| `auth` | register, login, forgot, reset | 5/min per email + 20/min per IP |
| `public-forms` | contact, subscribe, goat enquiry, seller apply, seller documents, order payment, order refund | 6/min |
| `checkout` | `POST /checkout` | 10/min |
| `api` | everything under `/api` | 90/min |

---

## 14. Guardrails worth not breaking

1. **Delivered means paid for.** Enforced in `OrderObserver::updating()`, so it holds
   for staff, sellers and the API alike. Also protects the seller ledger — earnings
   settle on delivered, so a delivery recorded against an unpaid order would let a
   seller draw a payout for money the platform never received.
2. **`paid_amount` is derived, never written.** Anything that writes it directly gets
   silently undone by the next `sync()`. Go through `PaymentService`.
3. **Commission and prices are frozen per line at purchase.** Changing a rate or a
   price later never moves a past sale.
4. **`soleSellerId()` queries, it does not read the relation.** Reading a filtered
   eager-load would mark every mixed order seller-managed.
5. **Approved listings are locked.** Only `draft` and `rejected` are editable, so a
   seller cannot swap the animal after moderation.
6. **Cancel stays available to staff on every order**, seller-run included. It is an
   intervention, not a step.
7. **Payouts stamp their lines inside the transaction.** Double payment is impossible;
   a failed payout releases them again — including the delivery charge, which would
   otherwise be owed forever and never paid.
8. **Notifications fire after commit.** A rolled-back order never emails anyone.
9. **Delivery is credited once per order** on the earnings statement, not once per line.
10. **The buyer's phone is withheld from sellers while the order is `pending`.**
11. **Do not put Seller identity/payout fields in `$hidden`** — it blanks the admin form.
12. **Status roll-up and carry-down are both forward-only**, and line writes do not
    re-trigger the roll-up, so there is no loop.

---

## 15. Deliberately still open

| Item | Why it is acceptable for now |
|---|---|
| Email verification | `email_verified_at` unused; orders are confirmed by phone |
| Invoice PDF | Needs a rendering package; order data is already complete |
| Full-text search | `LIKE` is fine at this catalogue size |
| Image optimisation | Uploads stored at original size |
| Multi-currency / multi-language | Single currency, English only |
| Bot-aware view counting | `views` increments on every detail request |
| Delivery zone vs city validation | Nothing cross-checks the zone against the typed city |
| General audit log | Only order status changes are recorded |

---

## 16. Operational notes

- `QUEUE_CONNECTION=sync` — notifications send inline. For production set `database`
  and run `php artisan queue:work`.
- `MAIL_MAILER=log` — emails land in `storage/logs/laravel.log`. Point at real SMTP
  before launch.
- Tests run against the `goat_marketplace_test` **MySQL** database, not SQLite, so
  migrations are exercised on the real engine.
- Locale: NPR / `रु` before the amount, `en-IN` digit grouping (lakhs),
  `Asia/Kathmandu`, `+977` phone format. `config/app.php` reads `APP_TIMEZONE` —
  it used to hardcode UTC, so setting the env var alone did nothing.
