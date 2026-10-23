<?php

namespace App\Models;

use App\Enums\HotspotSessionStatus;
use App\Support\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotspotSession extends Model
{
    /** @use HasFactory<\Database\Factories\HotspotSessionFactory> */
    use HasFactory, HasPublicId;

    protected $fillable = [
        'router_id',
        'voucher_id',
        'username',
        'session_key',
        'ip_address',
        'mac_address',
        'started_at',
        'last_seen_at',
        'ended_at',
        'status',
        'rx_bytes',
        'tx_bytes',
        'last_rx_bytes',
        'last_tx_bytes',
        'termination_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => HotspotSessionStatus::class,
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'rx_bytes' => 'integer',
            'tx_bytes' => 'integer',
            'last_rx_bytes' => 'integer',
            'last_tx_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }

    public function totalBytes(): int
    {
        return (int) $this->rx_bytes + (int) $this->tx_bytes;
    }
}
