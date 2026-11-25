<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Payments\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackController extends Controller
{
    public function callback(Request $request, PaystackService $paystack)
    {
        $reference = $request->query('reference');
        if (! $reference) {
            return redirect()->route('dashboard')->with('status', 'Missing payment reference.');
        }
        $verified = false;

        try {
            $result = $paystack->verify($reference);
            $verified = ($result['status'] ?? false)
                && (($result['data']['status'] ?? null) === 'success');
        } catch (\Throwable $e) {
            Log::error('paystack.callback.error', ['ref' => $reference, 'err' => $e->getMessage()]);
        }

        $transaction = Transaction::where('reference', $reference)->first();
        if ($transaction && $transaction->purchase) {
            return redirect()
                ->route('purchases.show', $transaction->purchase)
                ->with('status', $verified ? 'Payment verified.' : 'Payment could not be verified yet.');
        }
        return redirect()->route('transactions.index')->with('status', 'Payment processed.');
    }

    public function webhook(Request $request, PaystackService $paystack)
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.webhook_secret') ?: config('services.paystack.secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('paystack.webhook.misconfigured', ['message' => 'Webhook secret is not configured.']);

            abort(503, 'Paystack webhook secret is not configured.');
        }

        if (! is_string($signature) || ! hash_equals(hash_hmac('sha512', $payload, $secret), $signature)) {
            abort(401);
        }

        $event = $request->json()->all();
        if (($event['event'] ?? null) === 'charge.success') {
            $reference = $event['data']['reference'] ?? null;
            if ($reference) {
                try {
                    $paystack->verify($reference);
                } catch (\Throwable $e) {
                    Log::error('paystack.webhook.error', ['ref' => $reference, 'err' => $e->getMessage()]);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
