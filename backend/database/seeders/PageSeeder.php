<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'title'   => 'About us',
                'slug'    => 'about-us',
                'excerpt' => 'A family farm in Budhanilkantha that has been raising goats for twelve years.',
                'body'    => '<p>We started with four does behind the family house in Budhanilkantha. Twelve years later we run a working farm, a veterinary partnership and a delivery team that covers all all seven provinces.</p><h3>How we work</h3><p>Every animal on this site was either raised on our own land or bought from a farmer we have dealt with for years. Nothing is listed until a licensed vet has examined it, and nothing is listed at a weight we have not measured ourselves.</p><h3>Why cash on delivery</h3><p>Buying livestock online asks a lot of trust. Paying at the door means you see the animal, check it against the listing, and hand over money only when you are satisfied.</p>',
            ],
            [
                'title'   => 'Delivery information',
                'slug'    => 'delivery-information',
                'excerpt' => 'Zones, charges and what to expect on delivery day.',
                'body'    => '<h3>Zones and charges</h3><p>Delivery is priced by zone and shown at checkout once you choose yours. Inside Kathmandu Valley we deliver with our own vehicles and handlers. Outside Kathmandu we use a livestock courier we have worked with for years.</p><h3>On the day</h3><p>Our driver calls an hour before arriving. The goat travels fed and watered, with its vet certificate. You are welcome to weigh it yourself on arrival.</p><h3>If something is wrong</h3><p>If the animal does not match its listing, do not pay. Call us from the door and we will take it back at our own cost.</p>',
            ],
            [
                'title'   => 'Terms & conditions',
                'slug'    => 'terms-and-conditions',
                'excerpt' => 'The rules that apply when you order from us.',
                'body'    => '<h3>Orders</h3><p>An order is a request to buy. It becomes binding once we confirm it and mark the animal reserved. Prices and weights are accurate on the day of listing.</p><h3>Payment</h3><p>Payment is cash on delivery unless another method is offered at checkout. The full amount is due when the animal is handed over.</p><h3>Cancellation</h3><p>You may cancel free of charge at any point before the order moves to Out for delivery. After that, please call us.</p><h3>Health</h3><p>Every animal is sold with a veterinary certificate issued within seven days of delivery. We are not responsible for illness arising from housing or feeding after handover.</p>',
            ],
            [
                'title'   => 'Privacy policy',
                'slug'    => 'privacy-policy',
                'excerpt' => 'What we collect, why, and what we never do with it.',
                'body'    => '<h3>What we collect</h3><p>Your name, phone number, email address and delivery addresses. Nothing else is required to place an order.</p><h3>Why</h3><p>To process your order, arrange delivery and contact you about it. We use your email for order updates only, unless you subscribe to the newsletter.</p><h3>What we do not do</h3><p>We do not sell, rent or share your details with anyone outside our delivery partners, and they receive only what is needed to reach your door.</p>',
            ],
        ];

        foreach ($pages as $i => $page) {
            Page::updateOrCreate(
                ['slug' => $page['slug']],
                $page + [
                    'is_active'        => true,
                    'show_in_footer'   => true,
                    'meta_title'       => $page['title'],
                    'meta_description' => $page['excerpt'],
                    'sort_order'       => $i,
                ]
            );
        }
    }
}
