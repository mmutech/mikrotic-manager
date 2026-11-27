<?php

namespace App\Services;

use App\Enums\SubscriptionType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SubscriptionPricingService
{
    public const CURRENCY_KEY = 'subscription.currency';

    public const PRICE_KEY_PREFIX = 'subscription.price.';

    public function currency(): string
    {
        $setting = Setting::query()->where('key', self::CURRENCY_KEY)->first();
        $currency = $setting?->typedValue() ?: config('mmutech.subscription.currency', 'NGN');

        return strtoupper((string) $currency);
    }

    /**
     * @return array<int, array{value: string, label: string, days: int, price: float, currency: string}>
     */
    public function options(): array
    {
        $currency = $this->currency();

        return array_map(
            fn (SubscriptionType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'days' => $type->days(),
                'price' => $this->priceFor($type),
                'currency' => $currency,
            ],
            SubscriptionType::cases(),
        );
    }

    public function priceFor(SubscriptionType $type): float
    {
        $setting = Setting::query()->where('key', self::PRICE_KEY_PREFIX.$type->value)->first();
        $price = $setting?->typedValue() ?? config("mmutech.subscription.prices.{$type->value}", 0);

        return round(max(0, (float) $price), 2);
    }

    /**
     * @param  array<string, float|int|string>  $prices
     */
    public function updatePrices(array $prices, ?User $actor = null): void
    {
        DB::transaction(function () use ($prices, $actor): void {
            foreach (SubscriptionType::cases() as $type) {
                if (! array_key_exists($type->value, $prices)) {
                    continue;
                }

                Setting::query()->updateOrCreate(
                    ['key' => self::PRICE_KEY_PREFIX.$type->value],
                    [
                        'value' => (string) round(max(0, (float) $prices[$type->value]), 2),
                        'type' => 'float',
                        'updated_by' => $actor?->getKey(),
                    ],
                );
            }
        });
    }
}
