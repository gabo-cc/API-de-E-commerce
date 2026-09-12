<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::with(['user', 'items.product'])
            ->latest();

        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return OrderResource::collection(
            $query->paginate(10)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = DB::transaction(function () use ($request) {
            $total = 0;
            $orderItems = [];

            foreach ($request->validated('items') as $index => $item) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item['product_id']);

                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        "items.$index.product_id" =>
                        'El producto seleccionado no está disponible.',
                    ]);
                }

                if ($product->stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" =>
                        "Existencias insuficientes para {$product->name}. Disponibles: {$product->stock}.",
                    ]);
                }

                $unitPrice = (float) $product->price;
                $subtotal = round($unitPrice * $item['quantity'], 2);

                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                ];

                $total += $subtotal;

                $product->decrement('stock', $item['quantity']);
            }

            $order = $request->user()->orders()->create([
                'total' => round($total, 2),
                'status' => 'pending',
                'currency' => 'USD',
            ]);

            $order->items()->createMany($orderItems);

            return $order;
        });

        $order->load(['user', 'items.product']);

        return response()->json([
            'message' => 'Orden creada correctamente',
            'data' => new OrderResource($order),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Order $order): OrderResource
    {
        if (
            ! $request->user()->isAdmin()
            && $order->user_id !== $request->user()->id
        ) {
            abort(403, 'No tienes permiso para consultar esta orden.');
        }

        $order->load(['user', 'items.product']);

        return new OrderResource($order);
    }
}
