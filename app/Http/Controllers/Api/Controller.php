<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

abstract class Controller extends \App\Http\Controllers\Controller
{
    /**
     * Fields that are intentionally not supported by the mobile API.
     *
     * Voucher authentication is username/password only, and connected time is
     * a live RouterOS value rather than a writable allowance on the request.
     *
     * @param  array<int, string>  $fields
     */
    protected function rejectUnsupportedFields(Request $request, array $fields = []): void
    {
        $fields = $fields === []
            ? ['login_address', 'connected_time_allowance']
            : $fields;

        $messages = [];

        foreach ($fields as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $messages[$field] = match ($field) {
                'login_address' => 'Login address is not supported; vouchers authenticate with a username and password.',
                'connected_time_allowance' => 'Connected time allowance cannot be set here; connected time is read live from RouterOS.',
                default => 'This field is not supported.',
            };
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function json(
        array $data,
        ?string $message = null,
        int $status = SymfonyResponse::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}
