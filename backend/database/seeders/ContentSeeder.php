<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\HomeSection;
use App\Models\Testimonial;
use Illuminate\Database\Seeder;

class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->banners();
        $this->homeSections();
        $this->testimonials();
        $this->faqs();
    }

    private function banners(): void
    {
        $banners = [
            [
                'title'       => 'Healthy goats, honest weights',
                'subtitle'    => 'Straight from our farm',
                'description' => 'Every animal is weighed on a calibrated scale and vet-checked before it is listed. No surprises on delivery day.',
                'button_text' => 'Browse goats',
                'button_link' => '/shop',
            ],
            [
                'title'       => 'Dashain khasi, booked early',
                'subtitle'    => 'Reserve now, pay on delivery',
                'description' => 'Weight and teeth verified on every animal. Book ahead of the rush and we will hold it for you until Dashain.',
                'button_text' => 'See Dashain goats',
                'button_link' => '/shop?category=dashain-goats',
            ],
            [
                'title'       => 'Qurbani animals, age verified',
                'subtitle'    => 'For the Qurbani season',
                'description' => 'Age and teeth checked on every animal in the Qurbani category, so you can buy with confidence.',
                'button_text' => 'See Qurbani goats',
                'button_link' => '/shop?category=qurbani-goats',
            ],
            [
                'title'       => 'Free delivery inside Kathmandu Valley',
                'subtitle'    => 'On orders over 50,000',
                'description' => 'Our own transport, our own handlers. Your goat arrives calm, fed and watered.',
                'button_text' => 'Check delivery zones',
                'button_link' => '/pages/delivery-information',
            ],
        ];

        foreach ($banners as $i => $banner) {
            // Keyed on the slot, not the title: keying on title orphans the old
            // row every time the wording changes, leaving stale banners behind.
            Banner::updateOrCreate(
                ['placement' => 'hero', 'sort_order' => $i],
                $banner + [
                    'image'      => '',
                    'text_align' => 'left',
                    'is_active'  => true,
                ]
            );
        }
    }

    private function homeSections(): void
    {
        $why = ['items' => [
            ['icon' => 'shield-check', 'title' => 'Vet-checked', 'text' => 'Every goat is examined and certified before listing.'],
            ['icon' => 'speedometer2', 'title' => 'Honest weight', 'text' => 'Calibrated scale readings, photographed on request.'],
            ['icon' => 'truck', 'title' => 'Careful delivery', 'text' => 'Our own handlers, not a general courier.'],
            ['icon' => 'cash-coin', 'title' => 'Cash on delivery', 'text' => 'Inspect the animal first, then pay.'],
        ]];

        $stats = ['items' => [
            ['value' => '2,400+', 'label' => 'Goats delivered'],
            ['value' => '12', 'label' => 'Years farming'],
            ['value' => '64', 'label' => 'Districts covered'],
            ['value' => '4.8/5', 'label' => 'Average rating'],
        ]];

        $sections = [
            ['hero_slider', null, null, []],
            ['categories', 'Shop by purpose', 'Pick the right animal for what you need it for', ['limit' => 6, 'columns' => 3]],
            ['featured_goats', 'Featured goats', "Hand-picked from this week's stock", ['limit' => 8, 'columns' => 4]],
            ['why_choose_us', 'Why buy from us', 'Four things we will not compromise on', $why],
            ['stats', 'Our numbers', null, $stats],
            ['latest_goats', 'Just added', 'Fresh listings from the farm', ['limit' => 8, 'columns' => 4]],
            ['promo_banner', null, null, ['placement' => 'promo_strip']],
            ['testimonials', 'What buyers say', 'Feedback from people who bought from us', ['limit' => 6]],
            ['blog', 'Care guides', 'How to keep your goat healthy at home', ['limit' => 3]],
            ['faq', 'Common questions', 'Everything people ask before ordering', ['group' => 'general', 'limit' => 6]],
            ['cta', 'Not sure which goat to pick?', 'Call us and we will help you choose the right animal for your budget.', ['button_text' => 'Talk to us', 'button_link' => '/contact']],
        ];

        foreach ($sections as $i => [$type, $title, $subtitle, $config]) {
            HomeSection::updateOrCreate(
                ['type' => $type],
                [
                    'title'      => $title,
                    'subtitle'   => $subtitle,
                    'config'     => $config,
                    'is_active'  => true,
                    'sort_order' => $i,
                ]
            );
        }
    }

    private function testimonials(): void
    {
        $testimonials = [
            ['Kamrul Hasan', 'Kalanki, Kathmandu', 'Ordered a khasi for Dashain. The weight matched the listing exactly and the delivery team was on time. Paid cash at the door, no fuss.', 5],
            ['Nusrat Jahan', 'Pokhara', 'Bought a Beetal doe for milk. She settled in within two days and is giving over two litres. The team answered every question before I ordered.', 5],
            ['Imran Sheikh', 'Biratnagar', 'Third year buying from them. The animals are always healthy and the photos are honest, so what you see is what arrives.', 5],
            ['Farhana Akter', 'Baneshwor, Kathmandu', 'The vet certificate gave me confidence. Delivery was a day late but they called ahead and explained why.', 4],
        ];

        foreach ($testimonials as $i => [$name, $designation, $quote, $rating]) {
            Testimonial::updateOrCreate(
                ['name' => $name],
                [
                    'designation' => $designation,
                    'quote'       => $quote,
                    'rating'      => $rating,
                    'is_active'   => true,
                    'sort_order'  => $i,
                ]
            );
        }
    }

    private function faqs(): void
    {
        $faqs = [
            ['general', 'How do I know the weight is accurate?', 'Every goat is weighed on a calibrated livestock scale on the day it is listed, and we photograph the reading on request. Weight can shift by a kilo or two before delivery, which we will tell you about in advance.'],
            ['general', 'Can I see the goat before paying?', 'Yes. Payment is cash on delivery, so you inspect the animal at your door and pay only once you are satisfied.'],
            ['general', 'Are the goats vaccinated?', 'All animals are dewormed and vaccinated, and each one is examined by a licensed veterinarian before listing. The certificate travels with the goat.'],
            ['ordering', 'Do I need an account to order?', 'Yes. An account lets you track your order, save delivery addresses and keep a wishlist. Registration takes under a minute.'],
            ['ordering', 'Can I cancel my order?', 'You can cancel from your account any time before the order moves to Out for delivery. After that, call us and we will do our best.'],
            ['ordering', 'Do you hold a goat if I book early?', 'Yes. Once your order is confirmed the animal is marked sold and taken off the shop.'],
            ['delivery', 'How much is delivery?', 'It depends on your zone: inside Kathmandu Valley, around Kathmandu, or the rest of the country. The exact charge appears at checkout once you pick your zone, and large orders inside Kathmandu Valley ship free.'],
            ['delivery', 'How long does delivery take?', 'One to two days inside Kathmandu Valley, two to three around Kathmandu, and three to five days elsewhere in the country.'],
        ];

        foreach ($faqs as $i => [$group, $question, $answer]) {
            Faq::updateOrCreate(
                ['question' => $question],
                [
                    'group'      => $group,
                    'answer'     => $answer,
                    'is_active'  => true,
                    'sort_order' => $i,
                ]
            );
        }
    }
}
