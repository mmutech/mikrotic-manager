<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterPlanProfile extends Model
{
    /** @use HasFactory<\Database\Factories\RouterPlanProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'router_id',
        'plan_id',
        'hotspot_profile',
        'last_synchronized_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_synchronized_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
