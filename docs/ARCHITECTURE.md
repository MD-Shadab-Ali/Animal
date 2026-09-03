# Goat Marketplace — Architecture

## Stack
| Layer | Technology |
|---|---|
| Frontend | Next.js 15 (App Router, JavaScript) + Bootstrap 5 |
| Backend | Laravel 13 (REST API) |
| Admin Panel | Filament v5 (`/admin`) |
| Database | MySQL 8 (`goat_marketplace`) |
| Auth | Laravel Sanctum (token-based, customers must register to order) |
| Payment | Cash on Delivery (pluggable driver — admin can enable more later) |

## Repo layout
```
Animal Marketplace/
├── backend/     Laravel 13 API + Filament admin
├── frontend/    Next.js storefront
└── docs/
```

## Core principle: everything is dynamic
No hardcoded content in the frontend. Every label, banner, menu link, price,
shipping rate, page body and SEO tag is read from the database and editable
in Filament. The frontend fetches:

- `/api/v1/settings`      → site name, logo, colours, contact, socials, currency
- `/api/v1/menus/{slug}`  → header/footer navigation, built by admin
- `/api/v1/home`          → ordered homepage sections the admin toggles on/off
- `/api/v1/pages/{slug}`  → about / terms / privacy / any custom page

## Database schema

### Catalog
| Table | Purpose |
|---|---|
| `categories` | Goat types/breeds. Self-nesting via `parent_id`. |
| `goats` | The product. Breed, age, weight, gender, colour, teeth, health status, price, sale price, stock, SEO fields. |
| `goat_images` | Multiple photos per goat, sortable, one primary. |
| `attributes` / `attribute_values` | Admin-defined spec rows (e.g. "Vaccinated: Yes") so new spec types need no code. |

### Sales
| Table | Purpose |
|---|---|
| `carts` / `cart_items` | Persistent per-user cart. |
| `orders` / `order_items` | Order header + line snapshot (price frozen at purchase). |
| `order_status_histories` | Audit trail of every status change. |
| `coupons` | Percentage/fixed discount, usage caps, date window. |
| `delivery_zones` | Admin-defined regions with their own shipping charge. |
| `payment_methods` | COD seeded; admin toggles availability. |

### Homestay
| Table | Purpose |
|---|---|
| `rooms` | A room let at the farm: type, how many it sleeps, per-night price, extra-guest fee, min/max nights. |
| `room_images` | Photo gallery per room. |
| `bookings` | Stay header: dates, guests, nights, totals, payment plan and what has been paid. |
| `booking_nights` | One row per night held, which is what makes a date unavailable to anyone else. |
| `booking_status_histories` | Audit trail of every status change, same shape as orders. |

### Customers
| Table | Purpose |
|---|---|
| `users` | Admins and customers share the table, split by `role`. |
| `addresses` | Saved shipping addresses per customer. |
| `wishlists` | Saved goats. |
| `reviews` | Customer ratings, admin-moderated. |

### Dynamic content (admin-controlled)
| Table | Purpose |
|---|---|
| `settings` | Key/value store, grouped and typed. Drives the whole site chrome. |
| `banners` | Hero slider entries with CTA + placement. |
| `home_sections` | Homepage blocks: type, order, visibility, per-block config JSON. |
| `pages` | CMS pages with slug + SEO meta. |
| `menus` / `menu_items` | Navigation trees built in admin. |
| `testimonials` | Customer quotes with photo + rating. |
| `faqs` | Grouped question/answer accordion. |
| `posts` / `post_categories` | Blog / care guides. |
| `contact_messages` | Contact form inbox. |
| `inquiries` | "Ask about this goat" enquiries tied to a goat. |

## Order lifecycle
`pending → confirmed → processing → out_for_delivery → delivered`
with `cancelled` reachable from any pre-delivery state. Statuses and their
colours are configurable; every transition is written to
`order_status_histories` with the acting user and an optional note.

## Booking lifecycle
`placed → confirmed → checked_in → checked_out`
with `cancelled` reachable from any state before check-out. Every transition is
written to `booking_status_histories`, the same way orders are.

Two things move a booking without staff touching it. Paying the balance in full
on the day of arrival checks the guest straight in, if the
`auto_check_in_on_payment` setting is on. Anything settled while that was switched
off is swept up by `bookings:check-in-arrivals`, which runs daily at 01:00 and
needs a cron entry calling `schedule:run` (see the README).

Availability is held in `booking_nights` rather than computed from date ranges:
one row per night, so a night is either taken or it is not. The database enforces
it — `booking_nights_room_id_night_unique` is a unique index on
`(room_id, night)` — so two people racing for the same night end with one insert
and one rejection, not two bookings.

## Auth flow
1. Customer registers → `POST /api/v1/auth/register` → Sanctum token.
2. Token stored client-side, sent as `Authorization: Bearer`.
3. Cart, wishlist, addresses, checkout and orders all require the token.
4. Admins (`role = admin`) log into Filament at `/admin` with session auth —
   a separate guard from the API.
