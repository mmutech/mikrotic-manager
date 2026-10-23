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
        try {
            $paystack->verify($reference);
        } catch (\Throwable $e) {
            Log::error('paystack.callback.error', ['ref' => $reference, 'err' => $e->getMessage()]);
        }
        $transaction = Transaction::where('reference', $reference)->first();
        if ($transaction && $transaction->purchase) {
            return redirect()->route('purchases.show', $transaction->purchase->public_id)->with('status', 'Payment verified.');
        }
        return redirect()->route('transactions.index')->with('status', 'Payment processed.');
    }

    public function webhook(Request $request, PaystackService $paystack)
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.webhook_secret') ?? config('services.paystack.secret');

        if ($secret && $signature) {
            $computed = hash_hmac('sha512', $payload, $secret);
            if (! hash_equals($computed, $signature)) {
                abort(401);
            }
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
