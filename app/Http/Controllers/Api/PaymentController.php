<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function store(
        StorePaymentRequest $request,
        Order $order
    ): JsonResponse {
        if (
            ! $request->user()->isAdmin()
            && $order->user_id !== $request->user()->id
        ) {
            return response()->json([
                'message' => 'No tienes permiso para pagar esta orden.',
            ], 403);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Esta orden ya fue pagada o no puede procesarse.',
            ], 409);
        }

        $stripeSecret = (string) config('services.stripe.secret');

        if ($stripeSecret === '') {
            return response()->json([
                'message' => 'Stripe no está configurado correctamente.',
            ], 500);
        }

        $payment = $order->payments()->create([
            'amount' => $order->total,
            'currency' => strtolower($order->currency),
            'status' => 'pending',
        ]);

        try {
            $stripe = new StripeClient($stripeSecret);

            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => (int) round((float) $order->total * 100),
                'currency' => strtolower($order->currency),
                'payment_method' => $request->validated('payment_method_id'),
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'description' => "Pago de la orden #{$order->id}",
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'user_id' => (string) $order->user_id,
                ],
            ]);

            DB::transaction(function () use (
                $payment,
                $paymentIntent,
                $order
            ): void {
                $payment->update([
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                    'failure_message' => null,
                    'paid_at' => $paymentIntent->status === 'succeeded'
                        ? now()
                        : null,
                ]);

                if ($paymentIntent->status === 'succeeded') {
                    $order->update([
                        'status' => 'paid',
                    ]);
                }
            });

            return response()->json([
                'message' => $paymentIntent->status === 'succeeded'
                    ? 'Pago procesado correctamente'
                    : 'El pago requiere una acción adicional',
                'data' => new PaymentResource($payment->refresh()),
                'client_secret' => $paymentIntent->client_secret,
            ], $paymentIntent->status === 'succeeded' ? 201 : 202);
        } catch (ApiErrorException $exception) {
            $payment->update([
                'status' => 'failed',
                'failure_message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible procesar el pago.',
                'error' => $exception->getMessage(),
            ], 422);
        }
    }
}
