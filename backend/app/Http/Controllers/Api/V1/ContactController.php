<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Goat;
use App\Models\Inquiry;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['nullable', 'email', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:30'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactMessage::create($data);

        return response()->json([
            'message' => 'Thanks for writing. We will get back to you shortly.',
        ], 201);
    }

    /** "Ask about this goat" form on the product page. */
    public function inquiry(Request $request, string $slug): JsonResponse
    {
        $goat = Goat::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'phone'   => ['required', 'string', 'max:30'],
            'email'   => ['nullable', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        Inquiry::create($data + [
            'goat_id' => $goat->id,
            'user_id' => $request->user()?->id,
            'status'  => 'new',
        ]);

        return response()->json([
            'message' => 'Enquiry sent. We will call you about this goat.',
        ], 201);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        Subscriber::updateOrCreate(
            ['email' => $data['email']],
            ['is_active' => true]
        );

        return response()->json(['message' => 'You are on the list.'], 201);
    }
}
