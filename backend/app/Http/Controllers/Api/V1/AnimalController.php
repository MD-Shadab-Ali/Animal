<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoatWeight;

/**
 * What a scanned ear tag resolves to.
 *
 * Deliberately unauthenticated: the point of the tag is that whoever is
 * standing next to the goat can check it, and at delivery that person is a
 * buyer with a phone, not a member of staff. Addressed by a random token
 * rather than the row id so the pen cannot be read by counting upwards.
 */
class AnimalController extends Controller
{
    /**
     * The animal's code as an image.
     *
     * Its own address rather than a data URI inside every order payload: an
     * order with several lines would otherwise carry several kilobytes of
     * base64 that the browser could have cached separately.
     */
    public function qr(string $token)
    {
        $animal = GoatWeight::where('token', $token)->firstOrFail();

        return response($animal->qrSvg(240), 200, [
            'Content-Type' => 'image/svg+xml',
            // The code is derived from a token that never changes, so it can
            // be kept for a long time.
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    public function show(string $token)
    {
        $animal = GoatWeight::with('goat')->where('token', $token)->firstOrFail();
        $goat = $animal->goat;

        return response()->json([
            'data' => [
                'weight_kg' => (float) $animal->weight_kg,
                'tag' => $animal->tag,
                // This animal's own photograph. Never the listing's gallery:
                // a stand-in picture on a page identifying one specific goat
                // would be answering "is this the right animal" with a lie.
                'image' => $animal->image_url,
                'status' => $animal->status,
                'status_label' => $animal->isAvailable() ? 'Available' : 'Sold',
                'price' => $animal->price(),

                /*
                 * This animal's own record, not the listing's.
                 *
                 * Every one of these used to be a single value on the listing
                 * standing in for fifteen goats at once. Null is kept as null
                 * rather than dressed up: a buyer holding the animal is better
                 * served by "not recorded" than by a number that belongs to
                 * some other goat in the same pen.
                 */
                'age_months' => $animal->age_months,
                'teeth' => $animal->teeth,
                'teeth_label' => $animal->teethLabel(),
                'color' => $animal->color,
                'health_status' => $animal->health_status,
                'is_vaccinated' => $animal->is_vaccinated,
                'vaccination_label' => $animal->vaccinationLabel(),
                'vet_checked_at' => $animal->vet_checked_at?->toDateString(),
                'dewormed_at' => $animal->dewormed_at?->toDateString(),
                'notes' => $animal->notes,

                'listing' => [
                    'name' => $goat->name,
                    'slug' => $goat->slug,
                    'sku' => $goat->sku,
                    'thumbnail' => $goat->thumbnail_url,
                    'breed' => $goat->breed,
                    'gender' => $goat->gender,
                ],
            ],
        ]);
    }
}
