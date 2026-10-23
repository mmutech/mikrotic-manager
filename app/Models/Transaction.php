<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'reference',
        'provider',
        'provider_reference',
        'customer_id',
        'purchase_id',
        'voucher_id',
        'plan_id',
        'router_id',
        'amount',
        'currency',
        'status',
        'channel',
        'gateway_response',
        'failure_reason',
        'paid_at',
        'verified_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
