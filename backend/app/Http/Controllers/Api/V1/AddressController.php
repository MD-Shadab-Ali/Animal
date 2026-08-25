<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AddressController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $request->user()->addresses()->orderByDesc('is_default')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $address = $request->user()->addresses()->create($this->validated($request));

        return response()->json([
            'message' => 'Address saved.',
            'data'    => new AddressResource($address),
        ], 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $this->assertOwns($request, $address);
        $address->update($this->validated($request));

        return response()->json([
            'message' => 'Address updated.',
            'data'    => new AddressResource($address->fresh()),
        ]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->assertOwns($request, $address);
        $address->delete();

        return response()->json(['message' => 'Address removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label'        => ['nullable', 'string', 'max:255'],
            'full_name'    => ['required', 'string', 'max:255'],
            'phone'        => ['required', 'string', 'max:30'],
            'address_line' => ['required', 'string', 'max:255'],
            'area'         => ['nullable', 'string', 'max:255'],
            'city'         => ['required', 'string', 'max:255'],
            'postal_code'  => ['nullable', 'string', 'max:20'],
            'notes'        => ['nullable', 'string', 'max:1000'],
            'is_default'   => ['nullable', 'boolean'],
        ]);
    }

    private function assertOwns(Request $request, Address $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 403);
    }
}
