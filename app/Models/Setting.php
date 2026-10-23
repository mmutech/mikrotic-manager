<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'is_encrypted',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    public function typedValue(): mixed
    {
        $value = $this->is_encrypted && filled($this->value)
            ? decrypt($this->value)
            : $this->value;

        return match ($this->type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
