<?php

namespace App\Services\Payments;

use App\Enums\PurchaseStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Router;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PaystackService
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = config('services.paystack.base_url');
        $this->secret = config('services.paystack.secret') ?? '';
    }

    public function initialize(Purchase $purchase): array
    {
        $purchase->loadMissing(['customer', 'plan']);
        $reference = 'PSK-'.strtoupper(Str::random(16));

        $transaction = Transaction::create([
            'reference' => $reference,
            'provider' => 'paystack',
            'customer_id' => $purchase->customer_id,
            'purchase_id' => $purchase->id,
            'plan_id' => $purchase->plan_id,
            'router_id' => $purchase->router_id,
            'amount' => $purchase->total_amount,
            'currency' => $purchase->currency,
            'status' => TransactionStatus::Pending,
            'channel' => 'card',
            'metadata' => ['purchase_public_id' => $purchase->public_id],
        ]);

        try {
            $response = Http::withToken($this->secret)
                ->acceptJson()
                ->post($this->baseUrl.'/transaction/initialize', [
                    'email' => $purchase->customer->email ?? 'customer@example.com',
                    'amount' => (int) round($purchase->total_amount * 100),
                    'currency' => $purchase->currency,
                    'reference' => $reference,
                    'callback_url' => route('paystack.callback'),
                    'metadata' => [
                        'purchase_id' => $purchase->public_id,
                        'plan_id' => $purchase->plan->code,
                    ],
                ]);
        } catch (Throwable $exception) {
            $transaction->update([
                'status' => TransactionStatus::Failed,
                'failure_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $data = $response->json();

        if ($response->successful() && ($data['status'] ?? false)) {
            $transaction->update([
                'gateway_response' => $data,
            ]);
            return [
                'authorization_url' => $data['data']['authorization_url'] ?? null,
                'access_code' => $data['data']['access_code'] ?? null,
                'reference' => $reference,
                'transaction_id' => $transaction->id,
            ];
        }

        $transaction->update([
            'status' => TransactionStatus::Failed,
            'failure_reason' => $data['message'] ?? 'Initialization failed',
            'gateway_response' => $data,
        ]);

        throw new \Exception($data['message'] ?? 'Paystack initialization failed');
    }

    public function verify(string $reference): array
    {
        $response = Http::withToken($this->secret)
            ->acceptJson()
            ->get($this->baseUrl.'/transaction/verify/'.$reference);

        $data = $response->json();

        $transaction = Transaction::where('reference', $reference)->first();

        if (! $transaction) {
            return ['status' => false, 'data' => $data];
        }

        if ($response->successful() && ($data['status'] ?? false)) {
            $status = $data['data']['status'] ?? 'failed';
            if ($status === 'success') {
                $transaction->update([
                    'status' => TransactionStatus::Paid,
                    'provider_reference' => $data['data']['reference'] ?? $reference,
                    'paid_at' => now(),
                    'verified_at' => now(),
                    'gateway_response' => $data,
                    'channel' => $data['data']['channel'] ?? $transaction->channel,
                ]);

                $purchase = $transaction->purchase;
                if ($purchase && $purchase->status === PurchaseStatus::PendingPayment) {
                    $purchase->update(['status' => PurchaseStatus::Paid]);
                }
            } elseif ($status === 'abandoned') {
                $transaction->update(['status' => TransactionStatus::Abandoned, 'gateway_response' => $data]);
            } else {
                $transaction->update([
                    'status' => TransactionStatus::Failed,
                    'gateway_response' => $data,
                    'failure_reason' => $data['data']['gateway_response'] ?? null,
                ]);
            }
        }

        return $data;
    }

    public function createPurchase(Customer $customer, Plan $plan, Router $router, int $quantity = 1): Purchase
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Purchase quantity must be at least one.');
        }

        $unit = $plan->price;
        $total = $unit * $quantity;

        return Purchase::create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'quantity' => $quantity,
            'unit_price' => $unit,
            'total_amount' => $total,
            'currency' => $plan->currency,
            'status' => PurchaseStatus::PendingPayment,
        ]);
    }
}
