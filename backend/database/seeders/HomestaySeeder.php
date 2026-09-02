<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomImage;
use Illuminate\Database\Seeder;

/**
 * Four rooms with everything filled in.
 *
 * Deliberately NOT called from DatabaseSeeder. That one runs in roughly forty
 * feature tests, and four rooms appearing in all of them would change counts
 * other tests assert on and slow every one of them down for data none of them
 * want. Run it by hand:
 *
 *     php artisan db:seed --class=HomestaySeeder
 *
 * Safe to run twice: rooms are matched on their slug and their galleries are
 * rebuilt rather than appended, so a second run corrects the rows instead of
 * leaving a room with eight photographs of itself.
 *
 * The photographs are real, and they are stored here rather than linked because
 * a storefront hotlinking somebody else's CDN breaks the day they move a file.
 * They came from Unsplash, whose licence permits commercial use without
 * attribution -- which is why they are not from an image search, where nearly
 * everything is somebody's copyright and none of it is licensed for a shop.
 */
class HomestaySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rooms() as $data) {
            $gallery = $data['gallery'];
            unset($data['gallery']);

            /*
             * withTrashed(), and this is not a detail.
             *
             * Room soft-deletes, so a room removed in the admin is still in the
             * table holding its slug -- and `slug` is unique. updateOrCreate()
             * queries through the default scope, does not see the deleted row,
             * decides the room is new, and inserts a duplicate slug straight
             * into the unique index. Re-running this after deleting any room
             * died on a 1062 rather than doing anything useful.
             *
             * Asking for the demo rooms is asking for all of them, so a trashed
             * one comes back rather than blocking the run.
             */
            $room = Room::withTrashed()->firstWhere('slug', $data['slug']);

            if ($room) {
                $room->restore();
                $room->update($data);
            } else {
                $room = Room::create($data);
            }

            // Rebuilt, not appended. Running this twice should leave the room
            // as described, not as described twice.
            $room->images()->delete();

            foreach ($gallery as $sort => [$path, $alt]) {
                RoomImage::create([
                    'room_id' => $room->id,
                    'path' => $path,
                    'alt' => $alt,
                    'sort_order' => $sort,
                ]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function rooms(): array
    {
        return [
            [
                'name' => 'Terrace Room',
                'slug' => 'terrace-room',
                'code' => 'RM-TERRACE',
                'thumbnail' => 'rooms/terrace-room.jpg',
                'room_type' => 'Double',

                // Sleeps three, but the rate buys two. The third head is a
                // folding bed, and costs what a folding bed costs.
                'max_guests' => 3,
                'base_guests' => 2,
                'beds' => 1,
                'has_private_bathroom' => true,

                'price_per_night' => 4500,
                'extra_guest_fee' => 900,
                'min_nights' => 1,
                'max_nights' => 10,

                'short_description' => 'A double on the top floor, with the whole valley through '
                    .'one wall of glass and the goat sheds just out of sight below.',

                'description' => '<p>The room the farm gives people it likes. It sits at the end of '
                    .'the upper landing, away from the kitchen, and takes the morning sun across '
                    .'the terraced fields before anything else does.</p>'
                    .'<h3>What it is like</h3>'
                    .'<p>One king bed under a sloped timber ceiling, a glazed wall onto the '
                    .'balcony, and a bathroom of its own with hot water any hour you want it. The '
                    .'wood stove is laid before you arrive between November and February.</p>'
                    .'<ul>'
                    .'<li>Quietest room in the house — nothing above you, nothing behind you</li>'
                    .'<li>Balcony big enough for two chairs and a pot of tea</li>'
                    .'<li>Ten minutes down the track to the pens, if you want to meet the goats</li>'
                    .'</ul>'
                    .'<p><strong>Worth knowing:</strong> the stairs to this floor are steep and '
                    .'wooden. Tell us if that is a problem and we will put you in the Garden Twin '
                    .'instead, which is on the flat.</p>',

                'amenities' => [
                    ['label' => 'Bed', 'value' => 'One king'],
                    ['label' => 'View', 'value' => 'Valley and terraced fields'],
                    ['label' => 'Bathroom', 'value' => 'Private, hot water all day'],
                    ['label' => 'Heating', 'value' => 'Wood stove, laid in winter'],
                    ['label' => 'Wi-Fi', 'value' => 'Yes — patchy in heavy rain'],
                    ['label' => 'Breakfast', 'value' => 'Included: farm eggs, curd and tea'],
                    ['label' => 'Floor', 'value' => 'Top — steep wooden stairs'],
                ],

                'status' => 'published',
                'is_featured' => true,
                'sort_order' => 1,

                'meta_title' => 'Terrace Room — stay at Goat Haven farm',
                'meta_description' => 'A quiet double with a valley view, private bathroom and a '
                    .'wood stove. Breakfast included, ten minutes from the goat pens.',

                'gallery' => [
                    ['rooms/terrace-room.jpg', 'The bed, and the valley through the glazed wall'],
                    ['rooms/gallery/terrace-room-window.jpg', 'Casement window over the fields, with the bed alongside'],
                    ['rooms/gallery/terrace-room-3.jpg', 'The view down the valley on a clear morning'],
                    ['rooms/gallery/terrace-room-4.jpg', 'Green hills from the balcony'],
                ],
            ],

            [
                'name' => 'Barn Loft',
                'slug' => 'barn-loft',
                'code' => 'RM-LOFT',
                'thumbnail' => 'rooms/barn-loft.jpg',
                'room_type' => 'Family loft',

                'max_guests' => 5,
                'base_guests' => 4,
                'beds' => 3,
                'has_private_bathroom' => true,

                'price_per_night' => 6200,
                'extra_guest_fee' => 800,

                // Two nights minimum. It is the biggest room in the house, and
                // turning it round for one night costs more than it earns.
                'min_nights' => 2,
                'max_nights' => 14,

                'short_description' => 'The whole top of the old hay barn — beams, skylights and '
                    .'room for a family, five minutes across the yard from the house.',

                'description' => '<p>The hay barn stopped holding hay in 2019 and now holds people. '
                    .'The frame is the original chestnut, the floor is new, and the whole length of '
                    .'it is yours — there is no other room up here.</p>'
                    .'<h3>What it is like</h3>'
                    .'<p>A double at the gable end and two singles under the slope, with a sitting '
                    .'area between them and a bathroom at the top of the stair. Skylights over '
                    .'every bed, which is either the best or the worst thing about it depending on '
                    .'how you feel about waking at first light.</p>'
                    .'<ul>'
                    .'<li>Sleeps four comfortably, five with the folding bed</li>'
                    .'<li>Kettle, cups and a small fridge — no cooking up here</li>'
                    .'<li>Its own door onto the yard, so late arrivals wake nobody</li>'
                    .'</ul>'
                    .'<p><strong>Two nights minimum.</strong> It is a big room to turn round and we '
                    .'would rather do it well than often. Ring us if you need one night and we will '
                    .'see what the calendar looks like.</p>',

                'amenities' => [
                    ['label' => 'Beds', 'value' => 'One double, two singles'],
                    ['label' => 'Sleeps', 'value' => 'Four, or five with the folding bed'],
                    ['label' => 'Bathroom', 'value' => 'Private, at the top of the stair'],
                    ['label' => 'Building', 'value' => 'The old hay barn, across the yard'],
                    ['label' => 'Light', 'value' => 'Skylights over every bed'],
                    ['label' => 'Kitchen', 'value' => 'Kettle and a small fridge — no cooking'],
                    ['label' => 'Access', 'value' => 'Own door onto the yard'],
                ],

                'status' => 'published',
                'is_featured' => true,
                'sort_order' => 2,

                'meta_title' => 'Barn Loft — family room at Goat Haven farm',
                'meta_description' => 'The whole top floor of the old hay barn: chestnut beams, '
                    .'skylights, sleeps up to five. Two nights minimum.',

                'gallery' => [
                    ['rooms/barn-loft.jpg', 'Under the beams, looking through to the bedroom'],
                    ['rooms/gallery/barn-loft-2.jpg', 'The sitting area and the loft above it'],
                    ['rooms/gallery/barn-loft-3.jpg', 'Bed and bookshelf at the gable end'],
                    ['rooms/gallery/barn-loft-4.jpg', 'The two singles under the slope'],
                ],
            ],

            [
                'name' => 'Garden Twin',
                'slug' => 'garden-twin',
                'code' => 'RM-TWIN',
                'thumbnail' => 'rooms/garden-twin.jpg',
                'room_type' => 'Twin',

                // Two beds, two people, and no room for a third -- so the extra
                // guest fee is left empty rather than set to a figure nobody can
                // ever actually be charged.
                'max_guests' => 2,
                'base_guests' => 2,
                'beds' => 2,
                'has_private_bathroom' => false,

                'price_per_night' => 2800,
                'extra_guest_fee' => null,
                'min_nights' => 1,
                'max_nights' => 14,

                'short_description' => 'Two iron-framed singles on the ground floor, three windows '
                    .'onto the kitchen garden, and no stairs anywhere.',

                'description' => '<p>The cheapest room and the easiest to reach: off the hall, on '
                    .'the flat, with the kitchen garden on two sides. Friends travelling together '
                    .'take this one, and so does anybody who would rather not meet a staircase at '
                    .'the end of a long drive.</p>'
                    .'<h3>What it is like</h3>'
                    .'<p>Two proper single beds with iron frames, a rug over boards, and enough '
                    .'window that you will not want the lamp on until evening. The bathroom is '
                    .'across the hall and shared with one other room.</p>'
                    .'<ul>'
                    .'<li>Ground floor, no steps from the front door</li>'
                    .'<li>Shared bathroom, three paces across the hall</li>'
                    .'<li>Opens onto the garden, which is where breakfast happens in summer</li>'
                    .'</ul>'
                    .'<p><em>Two beds means two people.</em> We cannot add a third in here — the '
                    .'Barn Loft is the room for that.</p>',

                'amenities' => [
                    ['label' => 'Beds', 'value' => 'Two singles, iron frames'],
                    ['label' => 'Bathroom', 'value' => 'Shared with one other room, across the hall'],
                    ['label' => 'Floor', 'value' => 'Ground — no steps from the door'],
                    ['label' => 'Outlook', 'value' => 'Kitchen garden on two sides'],
                    ['label' => 'Heating', 'value' => 'Radiator, and extra quilts in the press'],
                    ['label' => 'Breakfast', 'value' => 'Included — in the garden when it is dry'],
                ],

                'status' => 'published',
                'is_featured' => false,
                'sort_order' => 3,

                'meta_title' => 'Garden Twin — ground-floor twin room at Goat Haven farm',
                'meta_description' => 'Two singles on the ground floor with no stairs, onto the '
                    .'kitchen garden. Shared bathroom. Breakfast included.',

                'gallery' => [
                    ['rooms/garden-twin.jpg', 'The two singles, with the garden through three windows'],
                    ['rooms/gallery/garden-twin-2.jpg', 'The room from the door'],
                    ['rooms/gallery/garden-twin-3.jpg', 'Plain white walls and morning light'],
                    ['rooms/gallery/garden-twin-4.jpg', 'The window onto the kitchen garden'],
                ],
            ],

            [
                'name' => "Shepherd's Hut",
                'slug' => 'shepherds-hut',
                'code' => 'RM-HUT',
                'thumbnail' => 'rooms/shepherds-hut.jpg',
                'room_type' => 'Cabin',

                'max_guests' => 2,
                'base_guests' => 2,
                'beds' => 1,
                'has_private_bathroom' => true,

                'price_per_night' => 3600,
                'extra_guest_fee' => null,
                'min_nights' => 1,

                // A week is as long as anybody has wanted it, and it is the one
                // room out of sight of the house -- worth keeping stays short
                // enough that somebody looks in between them.
                'max_nights' => 7,

                'short_description' => 'One pine-lined room at the top of the field, a double bed, '
                    .'a wood stove, and nothing else within earshot.',

                'description' => '<p>Built for whoever was watching the flock overnight, and kept '
                    .'more or less as it was: pine boards floor to ceiling, one window onto the '
                    .'trees, and a stove that heats the whole thing in about ten minutes.</p>'
                    .'<h3>What it is like</h3>'
                    .'<p>A double bed, a small shower room built into the end, and a lamp you will '
                    .'want on by four in the afternoon in winter. It is a five-minute walk up from '
                    .'the house across grass — take a torch, and boots if it has rained.</p>'
                    .'<ul>'
                    .'<li>Genuinely quiet. The nearest building is the house, and you cannot see it</li>'
                    .'<li>Wood stove, with a basket of split logs by the door</li>'
                    .'<li>Breakfast is down at the house, from seven</li>'
                    .'</ul>'
                    .'<p><strong>Not the room for everyone.</strong> No Wi-Fi reaches it, the walk '
                    .'up is unlit, and in January it is cold until the stove catches. People either '
                    .'love it or ask to move on the first night.</p>',

                'amenities' => [
                    ['label' => 'Bed', 'value' => 'One double'],
                    ['label' => 'Bathroom', 'value' => 'Private shower room, built into the end'],
                    ['label' => 'Heating', 'value' => 'Wood stove, logs provided'],
                    ['label' => 'Wi-Fi', 'value' => 'None — it does not reach up the field'],
                    ['label' => 'Walk', 'value' => 'Five minutes up from the house, unlit'],
                    ['label' => 'Breakfast', 'value' => 'Down at the house, from seven'],
                    ['label' => 'Best for', 'value' => 'People who wanted quiet and meant it'],
                ],

                'status' => 'published',
                'is_featured' => false,
                'sort_order' => 4,

                'meta_title' => "Shepherd's Hut — off-grid cabin at Goat Haven farm",
                'meta_description' => 'A pine-lined hut at the top of the field with a double bed, '
                    .'a wood stove and no Wi-Fi. Five minutes from the house.',

                'gallery' => [
                    ['rooms/shepherds-hut.jpg', 'Pine boards, the bed, and the window onto the trees'],
                    ['rooms/gallery/shepherds-hut-2.jpg', 'The stove end of the hut'],
                    ['rooms/gallery/shepherds-hut-3.jpg', 'A bed frame cut from the farm’s own timber'],
                    ['rooms/gallery/shepherds-hut-4.jpg', 'Looking out over the field at dusk'],
                ],
            ],
        ];
    }
}
