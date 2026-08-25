<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::with('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $orderNumber): OrderResource
    {
        $order = Order::with(['items.goat', 'deliveryZone', 'statusHistories'])
            ->where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return new OrderResource($order);
    }

    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        if (! $order->isCancellable()) {
            return response()->json([
                'message' => 'This order can no longer be cancelled. Please call us instead.',
            ], 422);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Order cancelled.',
            'data'    => new OrderResource($order->fresh()->load('items')),
        ]);
    }
}
