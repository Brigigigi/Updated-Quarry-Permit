<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

class StripePaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    }

    /**
     * Create a Stripe Checkout Session
     */
    public function createCheckoutSession(Request $request)
    {
        $request->validate([
            'tracking_id' => 'required|string',
        ]);

        $trackingId = $request->input('tracking_id');

        // Get fee assessment amount
        $feeAmount = null;
        $appId = null;

        try {
            if (Schema::hasTable('permit_application') && Schema::hasTable('fee_assessment')) {
                $appRow = DB::table('permit_application')->where('tracking_id', $trackingId)->first();

                if (!$appRow) {
                    return response()->json(['message' => 'Application not found'], 404);
                }

                $appId = $appRow->id;

                $latestFee = DB::table('fee_assessment')
                    ->where('application_id', $appId)
                    ->orderByDesc('created_at')
                    ->first();

                if (!$latestFee) {
                    return response()->json(['message' => 'No fee assessment found for this application'], 404);
                }

                $feeAmount = (float)$latestFee->total_amount;
            } else {
                return response()->json(['message' => 'Fee assessment system not available'], 500);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error retrieving fee: ' . $e->getMessage()], 500);
        }

        if (!$feeAmount || $feeAmount <= 0) {
            return response()->json(['message' => 'Invalid fee amount'], 400);
        }

        try {
            // Convert PHP pesos to cents (Stripe requires amount in smallest currency unit)
            $amountInCents = (int)($feeAmount * 100);

            $session = StripeSession::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'php',
                        'product_data' => [
                            'name' => 'Quarry Permit Fee',
                            'description' => 'Application Fee for Tracking ID: ' . $trackingId,
                        ],
                        'unit_amount' => $amountInCents,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => env('APP_URL') . '?payment=success&tracking_id=' . urlencode($trackingId) . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => env('APP_URL') . '?payment=cancelled&tracking_id=' . urlencode($trackingId),
                'metadata' => [
                    'tracking_id' => $trackingId,
                    'application_id' => $appId,
                ],
            ]);

            return response()->json([
                'session_id' => $session->id,
                'url' => $session->url,
                'amount' => $feeAmount,
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return response()->json(['message' => 'Stripe error: ' . $e->getMessage()], 500);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Payment session creation failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Verify payment after Stripe redirect
     */
    public function verifyPayment(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'tracking_id' => 'required|string',
        ]);

        $sessionId = $request->input('session_id');
        $trackingId = $request->input('tracking_id');

        try {
            // Retrieve the session from Stripe
            $session = StripeSession::retrieve($sessionId);

            if ($session->payment_status === 'paid') {
                // Update payment status in database
                $this->updatePaymentStatus($trackingId, $session);

                return response()->json([
                    'status' => 'paid',
                    'message' => 'Payment successful',
                    'tracking_id' => $trackingId,
                ]);
            }

            return response()->json([
                'status' => 'pending',
                'message' => 'Payment not completed',
            ], 400);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Payment verification failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update payment status in database and status.json
     */
    private function updatePaymentStatus(string $trackingId, $stripeSession)
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $trackingId);

        // Update status.json for immediate UI reflection
        $dir = storage_path('app/applications/' . $safe);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $statusPath = $dir . DIRECTORY_SEPARATOR . 'status.json';
        $current = file_exists($statusPath) ? (json_decode(@file_get_contents($statusPath), true) ?: []) : [];

        $current['paid'] = true;
        $current['payment'] = [
            'method' => 'stripe',
            'reference' => $stripeSession->id,
            'amount' => $stripeSession->amount_total / 100, // Convert cents back to pesos
            'paid_at' => now()->toISOString(),
            'stripe_payment_intent' => $stripeSession->payment_intent ?? null,
        ];
        $current['updated_at'] = now()->toISOString();

        @file_put_contents($statusPath, json_encode($current));

        // Update database if available
        try {
            if (Schema::hasTable('permit_application')) {
                $appRow = DB::table('permit_application')->where('tracking_id', $trackingId)->first();

                if ($appRow) {
                    $appId = (int)$appRow->id;

                    // Update application status
                    DB::table('permit_application')
                        ->where('id', $appId)
                        ->update([
                            'status' => 'paid',
                            'updated_at' => now()
                        ]);

                    // Insert payment record if payment table exists
                    if (Schema::hasTable('payment')) {
                        DB::table('payment')->insert([
                            'application_id' => $appId,
                            'method' => 'stripe',
                            'reference' => $stripeSession->id,
                            'amount' => $stripeSession->amount_total / 100,
                            'paid_at' => now(),
                            'created_at' => now(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log error but don't fail - status.json is updated
            \Log::error('Failed to update payment in database: ' . $e->getMessage());
        }
    }

    /**
     * Get Stripe public key for frontend
     */
    public function getPublicKey()
    {
        return response()->json([
            'public_key' => env('STRIPE_PUBLIC_KEY'),
        ]);
    }
}
