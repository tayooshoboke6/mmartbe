<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class BaseController extends BaseController
{
    use AuthorizesRequests, ValidatesRequests, ApiResponse;

    /**
     * Handle exceptions in a consistent way
     *
     * @param \Exception $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleException(\Exception $e)
    {
        \Log::error($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->errorResponse(
            'An error occurred while processing your request',
            config('app.debug') ? $e->getMessage() : null,
            500
        );
    }

    /**
     * Handle validation errors in a consistent way
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleValidationErrors($validator)
    {
        return $this->validationErrorResponse($validator);
    }

    /**
     * Handle not found errors in a consistent way
     *
     * @param string $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleNotFound($message = 'Resource not found')
    {
        return $this->notFoundResponse($message);
    }

    /**
     * Handle unauthorized errors in a consistent way
     *
     * @param string $message
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleUnauthorized($message = 'Unauthorized access')
    {
        return $this->unauthorizedResponse($message);
    }
} 