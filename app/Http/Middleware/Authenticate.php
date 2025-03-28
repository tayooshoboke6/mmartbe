<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }

    /**
     * Handle an unauthenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $guards
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new \Illuminate\Auth\AuthenticationException(
            'Unauthenticated.', $guards
        );
    }

    /**
     * Cache key for token lookup
     */
    private const TOKEN_CACHE_KEY = 'token:';
    private const TOKEN_CACHE_TTL = 3600; // 1 hour

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string[]  ...$guards
     * @return mixed
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    public function handle($request, \Closure $next, ...$guards)
    {
        $token = $request->bearerToken();
        
        if ($token) {
            $cacheKey = self::TOKEN_CACHE_KEY . $token;
            
            // Try to get the authenticated user from cache
            $user = Cache::get($cacheKey);
            
            if ($user) {
                // If user found in cache, set it in the request
                $request->setUserResolver(function () use ($user) {
                    return $user;
                });
                
                return $next($request);
            }
        }
        
        // If not in cache, proceed with normal authentication
        $response = parent::handle($request, $next, ...$guards);
        
        // Cache the authenticated user for future requests
        if ($token && $request->user()) {
            Cache::put(self::TOKEN_CACHE_KEY . $token, $request->user(), self::TOKEN_CACHE_TTL);
        }
        
        return $response;
    }
} 