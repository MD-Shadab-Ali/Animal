<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ── General ──────────────────────────────────────────────
            ['general', 'site_name', 'Goat Haven', 'text', 'Site name'],
            ['general', 'site_tagline', 'Healthy, hand-picked goats delivered to your door', 'text', 'Tagline'],
            ['general', 'site_logo', null, 'image', 'Header logo'],
            ['general', 'footer_logo', null, 'image', 'Footer logo'],
            ['general', 'site_favicon', null, 'image', 'Favicon'],
            ['general', 'footer_about', 'We raise and source healthy, well-fed goats and deliver them across the country. Every animal is vet-checked before it leaves our farm.', 'textarea', 'Footer about text'],
            ['general', 'copyright_text', '© {year} Goat Haven. All rights reserved.', 'text', 'Copyright line', 'Use {year} for the current year'],

            // ── Contact ──────────────────────────────────────────────
            ['contact', 'contact_phone', '+977 9800-000000', 'text', 'Phone'],
            ['contact', 'contact_email', 'hello@goathaven.test', 'text', 'Email'],
            ['contact', 'contact_address', 'Ward 4, Budhanilkantha, Kathmandu 44600', 'textarea', 'Address'],
            ['contact', 'whatsapp_number', '9779800000000', 'text', 'WhatsApp number', 'Digits only, with country code'],
            ['contact', 'business_hours', 'Sun – Fri: 7:00 AM – 7:00 PM', 'text', 'Business hours'],
            ['contact', 'google_map_embed', null, 'textarea', 'Google Maps embed URL'],

            // ── Social ───────────────────────────────────────────────
            ['social', 'facebook_url', '#', 'text', 'Facebook'],
            ['social', 'instagram_url', '#', 'text', 'Instagram'],
            ['social', 'youtube_url', '#', 'text', 'YouTube'],
            ['social', 'twitter_url', null, 'text', 'X / Twitter'],
            ['social', 'tiktok_url', null, 'text', 'TikTok'],

            // ── Commerce ─────────────────────────────────────────────
            ['commerce', 'currency_code', 'NPR', 'text', 'Currency code'],
            ['commerce', 'currency_symbol', 'रु', 'text', 'Currency symbol'],
            ['commerce', 'number_locale', 'en-IN', 'text', 'Number format', 'en-IN groups digits the Nepali way (1,00,000). Use en-US for 100,000'],
            ['commerce', 'currency_position', 'before', 'text', 'Symbol position', 'before or after'],
            ['commerce', 'min_order_amount', '0', 'number', 'Minimum order amount'],
            ['commerce', 'enable_coupons', '1', 'boolean', 'Enable coupon codes'],
            ['commerce', 'enable_reviews', '1', 'boolean', 'Enable customer reviews'],
            ['commerce', 'enable_wishlist', '1', 'boolean', 'Enable wishlist'],
            ['commerce', 'low_stock_threshold', '2', 'number', 'Low stock warning at'],
            ['commerce', 'goats_per_page', '12', 'number', 'Goats per page'],

            // ── Marketplace ──────────────────────────────────────────
            ['marketplace', 'marketplace_enabled', '1', 'boolean', 'Allow other people to sell', 'Turn off to run the shop as a single farm again'],
            ['marketplace', 'default_commission_rate', '10', 'number', 'Commission rate (%)', 'Taken from each sale. Individual sellers can be given their own rate'],
            ['marketplace', 'seller_applications_open', '1', 'boolean', 'Accept new seller applications'],
            ['marketplace', 'min_payout_amount', '2000', 'number', 'Minimum payout amount'],
            ['marketplace', 'seller_terms', 'Sellers are responsible for the accuracy of every listing. Animals must be healthy, correctly weighed and available on the day of sale.', 'textarea', 'Seller terms'],

            // ── Appearance ───────────────────────────────────────────
            ['appearance', 'primary_color', '#15803D', 'color', 'Primary colour', 'Buttons, links and highlights'],
            ['appearance', 'secondary_color', '#22C55E', 'color', 'Secondary colour', 'Supporting green for badges'],
            ['appearance', 'accent_color', '#A16207', 'color', 'Accent colour', 'Used for the main call-to-action'],
            ['appearance', 'announcement_enabled', '1', 'boolean', 'Show announcement bar'],
            ['appearance', 'announcement_text', 'Free delivery inside Kathmandu Valley on orders over रु50,000', 'text', 'Announcement text'],
            ['appearance', 'announcement_link', '/shop', 'text', 'Announcement link'],

            // ── SEO ──────────────────────────────────────────────────
            ['seo', 'meta_title', 'Goat Haven — Buy healthy goats online', 'text', 'Default meta title'],
            ['seo', 'meta_description', 'Browse vet-checked goats by breed, weight and age. Cash on delivery across Nepal.', 'textarea', 'Default meta description'],
            ['seo', 'meta_keywords', 'goat, buy goat, dashain goat, khasi, khari goat, qurbani goat, boka, livestock, farm', 'text', 'Meta keywords'],
            ['seo', 'og_image', null, 'image', 'Social share image'],
            ['seo', 'google_analytics_id', null, 'text', 'Google Analytics ID'],
            ['seo', 'facebook_pixel_id', null, 'text', 'Facebook Pixel ID'],
        ];

        foreach ($settings as $i => $row) {
            Setting::updateOrCreate(
                ['key' => $row[1]],
                [
                    'group'      => $row[0],
                    'value'      => $row[2],
                    'type'       => $row[3],
                    'label'      => $row[4],
                    'hint'       => $row[5] ?? null,
                    'sort_order' => $i,
                ]
            );
        }
    }
}
