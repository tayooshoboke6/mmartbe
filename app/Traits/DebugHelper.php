<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait DebugHelper
{
    /**
     * Log debug information
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function debug($message, array $context = [])
    {
        if (config('app.debug')) {
            Log::debug($message, array_merge([
                'controller' => get_class($this),
                'method' => debug_backtrace()[1]['function'],
                'line' => debug_backtrace()[1]['line'],
            ], $context));
        }
    }

    /**
     * Log request data
     *
     * @param array $except
     * @return void
     */
    protected function debugRequest(array $except = ['password', 'password_confirmation'])
    {
        if (config('app.debug')) {
            $this->debug('Request Data', [
                'method' => request()->method(),
                'url' => request()->fullUrl(),
                'data' => request()->except($except),
                'headers' => request()->headers->all(),
            ]);
        }
    }

    /**
     * Log response data
     *
     * @param mixed $response
     * @return void
     */
    protected function debugResponse($response)
    {
        if (config('app.debug')) {
            $this->debug('Response Data', [
                'status' => $response->getStatusCode(),
                'content' => $response->getContent(),
            ]);
        }
    }

    /**
     * Log database queries
     *
     * @param \Closure $callback
     * @return mixed
     */
    protected function debugQueries(\Closure $callback)
    {
        if (config('app.debug')) {
            \DB::enableQueryLog();
            $result = $callback();
            $this->debug('Database Queries', [
                'queries' => \DB::getQueryLog(),
            ]);
            return $result;
        }
        return $callback();
    }

    /**
     * Log performance metrics
     *
     * @param \Closure $callback
     * @return mixed
     */
    protected function debugPerformance(\Closure $callback)
    {
        if (config('app.debug')) {
            $start = microtime(true);
            $result = $callback();
            $duration = microtime(true) - $start;
            
            $this->debug('Performance Metrics', [
                'duration' => round($duration * 1000, 2) . 'ms',
                'memory' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
            ]);
            
            return $result;
        }
        return $callback();
    }
} 