<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerListingResource;
use App\Models\Goat;
use App\Models\GoatImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SellerListingController extends Controller
{
    /** Enough to show an animal from every angle without a wall of photos. */
    private const MAX_IMAGES = 8;

    public function index(Request $request): AnonymousResourceCollection
    {
        $listings = Goat::with('category', 'images')
            ->where('seller_id', $request->user()->seller->id)
            // Filter by the same state the listing reports, so "Sold" is
            // reachable at all — it lives on `status`, not on approval.
            ->when($request->filled('state'),
                fn ($query) => $this->filterByState($query, $request->string('state')->toString()))
            ->when($request->filled('approval_status'),
                fn ($query) => $query->where('approval_status', $request->string('approval_status')))
            ->when($request->filled('search'),
                fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return SellerListingResource::collection($listings);
    }

    public function show(Request $request, Goat $goat): SellerListingResource
    {
        $this->assertOwns($request, $goat);

        return new SellerListingResource($goat->load('category', 'images'));
    }

    public function store(Request $request): JsonResponse
    {
        $goat = Goat::create($this->validated($request) + [
            'seller_id'       => $request->user()->seller->id,
            'status'          => 'draft',
            'approval_status' => 'draft',
        ]);

        return response()->json([
            'message' => 'Listing saved as a draft. Submit it when you are ready.',
            'data'    => new SellerListingResource($goat),
        ], 201);
    }

    public function update(Request $request, Goat $goat): JsonResponse
    {
        $this->assertOwns($request, $goat);
        $this->assertEditable($goat);

        $goat->update($this->validated($request));

        return response()->json([
            'message' => 'Listing updated.',
            'data'    => new SellerListingResource($goat->fresh()),
        ]);
    }

    /** Hand the listing to staff for review. */
    public function submit(Request $request, Goat $goat): JsonResponse
    {
        $this->assertOwns($request, $goat);

        if ($goat->approval_status === 'pending') {
            throw ValidationException::withMessages([
                'listing' => ['This listing is already waiting for review.'],
            ]);
        }

        if ($goat->approval_status === 'approved') {
            throw ValidationException::withMessages([
                'listing' => ['This listing is already approved.'],
            ]);
        }

        $goat->update([
            'approval_status'  => 'pending',
            'rejection_reason' => null,
            'submitted_at'     => now(),
            'status'           => 'published',
        ]);

        return response()->json([
            'message' => 'Sent for review. We will let you know once it is live.',
            'data'    => new SellerListingResource($goat->fresh()),
        ]);
    }

    /**
     * Attach photos to a listing.
     *
     * Nobody buys a goat they cannot see, and until now a seller had no way to
     * add one — the gallery and `goats.thumbnail` both existed, but only staff
     * could fill them, so every seller listing showed the empty placeholder.
     *
     * Gated on the listing being editable for the same reason its fields are:
     * once staff have approved it, the moderated version and the public one
     * have to stay the same.
     */
    public function uploadImages(Request $request, Goat $goat): JsonResponse
    {
        $this->assertOwns($request, $goat);
        $this->assertEditable($goat);

        $request->validate([
            'images'   => ['required', 'array', 'min:1', 'max:'.self::MAX_IMAGES],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'images.*.image' => 'Photos must be image files.',
            'images.*.max'   => 'Each photo must be 5MB or smaller.',
        ]);

        $existing = $goat->images()->count();

        if ($existing + count($request->file('images')) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => ['A listing can have at most '.self::MAX_IMAGES.' photos.'],
            ]);
        }

        $sort = (int) $goat->images()->max('sort_order');

        foreach ($request->file('images') as $file) {
            $goat->images()->create([
                'path'       => $file->store('goats/'.$goat->id, 'public'),
                'alt'        => $goat->name,
                'sort_order' => ++$sort,
            ]);
        }

        // The first photo doubles as the listing thumbnail, which is what the
        // shop grid and every order line show.
        if (blank($goat->thumbnail)) {
            $goat->update(['thumbnail' => $goat->images()->orderBy('sort_order')->value('path')]);
        }

        return response()->json([
            'message' => 'Photos added.',
            'data'    => new SellerListingResource($goat->fresh()->load('category', 'images')),
        ], 201);
    }

    /** Remove one photo, and hand the thumbnail on if it was the one. */
    public function deleteImage(Request $request, Goat $goat, GoatImage $image): JsonResponse
    {
        $this->assertOwns($request, $goat);
        $this->assertEditable($goat);

        abort_if($image->goat_id !== $goat->id, 404);

        $wasThumbnail = $goat->thumbnail === $image->path;

        Storage::disk('public')->delete($image->path);
        $image->delete();

        if ($wasThumbnail) {
            $goat->update(['thumbnail' => $goat->images()->orderBy('sort_order')->value('path')]);
        }

        return response()->json([
            'message' => 'Photo removed.',
            'data'    => new SellerListingResource($goat->fresh()->load('category', 'images')),
        ]);
    }

    public function destroy(Request $request, Goat $goat): JsonResponse
    {
        $this->assertOwns($request, $goat);

        if ($goat->orderItems()->exists()) {
            throw ValidationException::withMessages([
                'listing' => ['This goat has been ordered, so it cannot be deleted.'],
            ]);
        }

        $goat->delete();

        return response()->json(['message' => 'Listing removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'category_id'       => ['required', 'exists:categories,id'],
            'name'              => ['required', 'string', 'max:255'],
            'breed'             => ['nullable', 'string', 'max:255'],
            'age_months'        => ['nullable', 'integer', 'min:0', 'max:300'],
            'weight_kg'         => ['nullable', 'numeric', 'min:0', 'max:500'],
            'gender'            => ['required', 'in:male,female'],
            'color'             => ['nullable', 'string', 'max:255'],
            'teeth'             => ['nullable', 'integer', 'min:0', 'max:8'],
            'health_status'     => ['nullable', 'string', 'max:255'],
            'is_vaccinated'     => ['nullable', 'boolean'],
            'price'             => ['required', 'numeric', 'min:1'],
            'sale_price'        => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'stock'             => ['nullable', 'integer', 'min:0'],

            // The range of weights this listing can supply. The rate comes from
            // the asking price against the weight above, so there is no third
            // figure to enter. The advertised weight has to sit inside the
            // range -- a listing cannot offer 20-30 kg while advertising 41.
            'min_weight_kg'     => ['nullable', 'numeric', 'min:0.5', 'max:500', 'lte:weight_kg'],
            'max_weight_kg'     => ['nullable', 'numeric', 'min:0.5', 'max:500', 'gte:weight_kg'],
            'weight_step_kg'    => ['nullable', 'numeric', 'min:0.1', 'max:50'],

            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string', 'max:20000'],
            'video_url'         => ['nullable', 'url', 'max:255'],
            'specs'             => ['nullable', 'array'],
            'specs.*.label'     => ['required_with:specs', 'string', 'max:100'],
            'specs.*.value'     => ['required_with:specs', 'string', 'max:255'],
        ]);
    }

    private function assertOwns(Request $request, Goat $goat): void
    {
        abort_unless($goat->seller_id === $request->user()->seller->id, 403);
    }

    /** An approved listing is locked; edits would bypass moderation. */
    private function assertEditable(Goat $goat): void
    {
        if (! in_array($goat->approval_status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'listing' => ['This listing is under review or already live. Contact us to change it.'],
            ]);
        }
    }

    /** The query behind each state a listing can report. */
    private function filterByState(Builder $query, string $state): Builder
    {
        return match ($state) {
            'sold'     => $query->where('status', 'sold'),
            'archived' => $query->where('status', 'archived'),
            'live'     => $query->where('approval_status', 'approved')->where('status', 'published'),
            'hidden'   => $query->where('approval_status', 'approved')
                ->whereNotIn('status', ['published', 'sold', 'archived']),
            // draft, pending, rejected — and never a sold goat, which keeps
            // its approval long after it has gone.
            default    => $query->where('approval_status', $state)
                ->whereNotIn('status', ['sold', 'archived']),
        };
    }
}
