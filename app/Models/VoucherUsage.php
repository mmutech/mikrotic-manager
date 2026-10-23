<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherUsage extends Model
{
    public $timestamps = false;

    protected $table = 'voucher_usage';

    protected $fillable = [
        'voucher_id',
        'router_id',
        'hotspot_session_id',
        'sample_key',
        'sampled_at',
        'rx_bytes',
        'tx_bytes',
        'total_bytes',
        'delta_bytes',
        'counter_source',
        'counter_reset',
        'reconciliation_status',
        'raw',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'rx_bytes' => 'integer',
            'tx_bytes' => 'integer',
            'total_bytes' => 'integer',
            'delta_bytes' => 'integer',
            'counter_reset' => 'boolean',
            'raw' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function hotspotSession(): BelongsTo
    {
        return $this->belongsTo(HotspotSession::class);
    }
}
