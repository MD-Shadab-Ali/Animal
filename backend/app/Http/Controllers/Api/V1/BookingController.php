<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PaymentEntryResource;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A guest's own bookings.
 *
 * Everything here is scoped to the signed-in account by the query rather than
 * by a check afterwards -- a booking number is short enough to guess at, and
 * `where('user_id', ...)` turns somebody else's stay into a 404 instead of into
 * a decision.
 */
class BookingController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private PaymentService $payments,
    ) {}

    /** Book a room. */
    public function store(Request $request, string $slug): JsonResponse
    {
        $room = Room::published()->where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'check_in'       => ['required', 'date'],
            'check_out'      => ['required', 'date'],
            'guests'         => ['required', 'integer', 'min:1', 'max:20'],
            'payment_method' => ['required', 'string'],
            'payment_plan'   => ['nullable', 'string'],
            'guest_name'     => ['required', 'string', 'max:255'],
            'guest_phone'    => ['required', 'string', 'max:30'],
            'guest_email'    => ['nullable', 'email', 'max:255'],
            'guest_notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        // Everything deciding whether this stay may happen lives in the
        // service, including the transaction that holds the nights. The
        // controller's whole job is to hand it over and report back.
        $booking = $this->bookings->place($room, $request->user(), $data);

        return response()->json([
            'message' => 'Your room is booked.',
            'data' => new BookingResource($booking->load('room')),
        ], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $bookings = Booking::query()
            ->with('room')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('check_in')
            ->paginate(10);

        return BookingResource::collection($bookings);
    }

    public function show(Request $request, string $number): BookingResource
    {
        return new BookingResource(
            $this->find($request, $number)->load(['room', 'payments', 'statusHistories'])
        );
    }

    /** The guest calling it off. */
    public function cancel(Request $request, string $number): JsonResponse
    {
        $booking = $this->find($request, $number);

        if (! $booking->isCancellable()) {
            throw ValidationException::withMessages([
                'status' => [$booking->status === 'cancelled'
                    ? 'This booking is already cancelled.'
                    : 'This stay has already started, so it cannot be cancelled. Please call us.'],
            ]);
        }

        $booking->statusNote = 'Cancelled by the guest';
        $booking->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Your booking has been cancelled.',
            'data' => new BookingResource($booking->fresh()->load('room')),
        ]);
    }

    /**
     * A guest saying they have sent money.
     *
     * A claim, not a receipt -- staff confirm it against the account before the
     * booking moves. The same service the orders use, so an advance on a room
     * and an advance on a goat are checked and recorded identically.
     */
    public function pay(Request $request, string $number): JsonResponse
    {
        $booking = $this->find($request, $number);

        $data = $request->validate([
            'method'                => ['required', 'string'],
            'amount'                => ['required', 'numeric', 'min:1'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'note'                  => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->payments->claim($booking, $request->user(), $data);

        return response()->json([
            'message' => 'Thank you — we will confirm it shortly.',
            'data' => new PaymentEntryResource($payment),
        ], 201);
    }

    /** Asking for money back on a booking that no longer needs it. */
    public function refund(Request $request, string $number): JsonResponse
    {
        $booking = $this->find($request, $number);

        $data = $request->validate([
            'refund_to_name'    => ['nullable', 'string', 'max:255'],
            'refund_to_account' => ['nullable', 'string', 'max:255'],
            'refund_to_bank'    => ['nullable', 'string', 'max:255'],
            'refund_reason'     => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->payments->requestRefund($booking, $request->user(), $data);

        return response()->json([
            'message' => 'We have your refund request.',
            'data' => new PaymentEntryResource($payment),
        ], 201);
    }

    /**
     * Somebody else's booking is not found, rather than forbidden.
     *
     * A 403 confirms the number exists, which is the one thing a stranger
     * guessing at booking numbers would actually learn something from.
     */
    private function find(Request $request, string $number): Booking
    {
        return Booking::where('booking_number', $number)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
