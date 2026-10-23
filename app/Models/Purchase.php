<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Purchase extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'customer_id',
        'plan_id',
        'router_id',
        'quantity',
        'unit_price',
        'total_amount',
        'currency',
        'status',
        'fulfilled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'fulfilled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
