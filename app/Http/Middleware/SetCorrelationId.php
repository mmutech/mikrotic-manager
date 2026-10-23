<?php

namespace App\Http\Middleware;

use App\Support\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SetCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CorrelationContext::class);
        $id = $context->set($request->header('X-Request-ID'));

        Log::withContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }
}
