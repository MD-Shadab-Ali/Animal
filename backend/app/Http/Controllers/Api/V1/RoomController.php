<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\Setting;
use App\Services\BookingService;
use App\Support\Homestay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class RoomController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', 12);
        $perPage = max(1, min($perPage, 48));

        $query = Room::query()->with('images')->published();

        // How many people are actually coming. The first filter a guest reaches
        // for, and the one whose absence makes a listing useless: a family of
        // four scrolling past three doubles has learned nothing.
        $query->when($request->filled('guests'), fn ($q) => $q->where('max_guests', '>=', $request->integer('guests')));

        $query->when($request->filled('min_price'), fn ($q) => $q->where('price_per_night', '>=', $request->float('min_price')));
        $query->when($request->filled('max_price'), fn ($q) => $q->where('price_per_night', '<=', $request->float('max_price')));

        $query->when($request->boolean('featured'), fn ($q) => $q->featured());
        $query->when($request->boolean('ensuite'), fn ($q) => $q->where('has_private_bathroom', true));

        $query->when($request->filled('search'), function ($q) use ($request) {
            $term = '%'.$request->string('search').'%';

            $q->where(fn ($sub) => $sub
                ->where('name', 'like', $term)
                ->orWhere('room_type', 'like', $term)
                ->orWhere('short_description', 'like', $term));
        });

        /*
         * Dates narrow the list to rooms that are actually free.
         *
         * Answered from `booking_nights` rather than by walking the bookings,
         * because that is the table the unique index sits on -- so a room shown
         * as available here is one the database will accept a booking for.
         */
        [$from, $to] = $this->window($request);

        if ($from && $to) {
            $query->whereDoesntHave('nights', fn ($q) => $q
                ->where('night', '>=', $from->toDateString())
                ->where('night', '<', $to->toDateString()));
        }

        match ($request->string('sort')->toString()) {
            'price_asc' => $query->orderBy('price_per_night'),
            'price_desc' => $query->orderByDesc('price_per_night'),
            'sleeps' => $query->orderByDesc('max_guests'),
            default => $query->ordered(),
        };

        return RoomResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function show(string $slug): RoomResource
    {
        $room = Room::query()
            ->with('images')
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $room->increment('views');

        return new RoomResource($room);
    }

    /**
     * The nights this room is already spoken for.
     *
     * Its own endpoint as well as part of the detail payload, because a date
     * picker outlives the page load: somebody sitting on a room page for ten
     * minutes should be able to re-ask rather than book against a calendar that
     * was true when they arrived.
     */
    public function availability(Request $request, string $slug): JsonResponse
    {
        $room = Room::published()->where('slug', $slug)->firstOrFail();

        [$from, $to] = $this->window($request);

        return response()->json([
            'data' => [
                'taken' => $this->bookings->takenDates($room, $from, $to),
                'earliest_date' => Homestay::earliestDate()->toDateString(),
                'latest_date' => Homestay::latestDate()->toDateString(),
                'min_nights' => (int) $room->min_nights,
                'max_nights' => (int) $room->max_nights,
                'max_guests' => (int) $room->max_guests,
            ],
        ]);
    }

    /** What the homestay is like as a whole, for the top of the listing page. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'homestay' => Homestay::config(),
                'currency' => [
                    'code' => Setting::currencyCode(),
                    'symbol' => Setting::currencySymbol(),
                ],
                'payment_methods' => RoomResource::paymentOptions(),
            ],
        ]);
    }

    /**
     * The date range a request is asking about, or nulls when it named none.
     *
     * Both or neither: a from with no to is a question nobody can answer, and
     * guessing a length would filter the list against a stay the guest never
     * asked for.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function window(Request $request): array
    {
        if (! $request->filled('check_in') || ! $request->filled('check_out')) {
            return [null, null];
        }

        try {
            $from = CarbonImmutable::parse($request->string('check_in')->toString())->startOfDay();
            $to = CarbonImmutable::parse($request->string('check_out')->toString())->startOfDay();
        } catch (Throwable) {
            return [null, null];
        }

        return $to->gt($from) ? [$from, $to] : [null, null];
    }
}
