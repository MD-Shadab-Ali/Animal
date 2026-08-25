<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SellerListingResource;
use App\Models\Goat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SellerListingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $listings = Goat::with('category', 'images')
            ->where('seller_id', $request->user()->seller->id)
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
}
