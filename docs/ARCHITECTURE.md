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

## Auth flow
1. Customer registers → `POST /api/v1/auth/register` → Sanctum token.
2. Token stored client-side, sent as `Authorization: Bearer`.
3. Cart, wishlist, addresses, checkout and orders all require the token.
4. Admins (`role = admin`) log into Filament at `/admin` with session auth —
   a separate guard from the API.
