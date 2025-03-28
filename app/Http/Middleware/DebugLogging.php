<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DebugLogging
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (config('app.debug')) {
            Log::info('API Request', [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $request->user() ? $request->user()->id : null,
                'request_data' => $request->except(['password', 'password_confirmation']),
            ]);
        }

        $response = $next($request);

        if (config('app.debug')) {
            Log::info('API Response', [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'status' => $response->getStatusCode(),
                'response_data' => $response->getContent(),
            ]);
        }

        return $response;
    }
} 