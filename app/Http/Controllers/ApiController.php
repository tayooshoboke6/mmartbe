<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Traits\DebugHelper;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base API Controller
 * 
 * This controller provides common functionality for all API controllers including:
 * - Standardized response formats
 * - Error handling
 * - Authorization
 * - Validation
 * - Logging
 * 
 * @package App\Http\Controllers
 * @version 1.0.0
 */
abstract class ApiController extends BaseController
{
    use AuthorizesRequests, ValidatesRequests, ApiResponse, DebugHelper;

    /**
     * The model class associated with this controller
     *
     * @var string
     */
    protected $model;

    /**
     * The resource class for transforming model data
     *
     * @var string
     */
    protected $resource;

    /**
     * The collection resource class for transforming model collections
     *
     * @var string
     */
    protected $collectionResource;

    /**
     * The form request class for validation
     *
     * @var string
     */
    protected $formRequest;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('api');
    }

    /**
     * Display a listing of the resource.
     *
     * @api {get} /api/{resource} List all resources
     * @apiName ListResources
     * @apiGroup Resources
     * @apiVersion 1.0.0
     *
     * @apiParam {Number} [page=1] Page number
     * @apiParam {Number} [per_page=15] Items per page
     * @apiParam {String} [sort_by=created_at] Field to sort by
     * @apiParam {String} [sort_direction=desc] Sort direction (asc/desc)
     * @apiParam {String} [search] Search term
     * @apiParam {Boolean} [include_inactive=false] Include inactive items
     *
     * @apiSuccess {Object[]} data Array of resources
     * @apiSuccess {Number} meta.current_page Current page number
     * @apiSuccess {Number} meta.total Total number of resources
     * @apiSuccess {Number} meta.per_page Items per page
     *
     * @apiError {Object} error Error object
     * @apiError {String} error.message Error message
     * @apiError {Number} error.code Error code
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $this->debugRequest();
            
            $response = $this->debugPerformance(function () {
                return $this->debugQueries(function () {
                    $query = $this->model::query();

                    // Apply filters
                    if (request()->has('search')) {
                        $query->where('name', 'like', '%' . request('search') . '%');
                    }

                    if (!request('include_inactive', false)) {
                        $query->where('is_active', true);
                    }

                    // Apply sorting
                    $sortBy = request('sort_by', 'created_at');
                    $sortDirection = request('sort_direction', 'desc');
                    $query->orderBy($sortBy, $sortDirection);

                    // Paginate results
                    $perPage = request('per_page', 15);
                    $items = $query->paginate($perPage);

                    return $this->successResponse(
                        new $this->collectionResource($items),
                        'Resources retrieved successfully'
                    );
                });
            });

            $this->debugResponse($response);
            return $response;
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @api {post} /api/{resource} Create a new resource
     * @apiName CreateResource
     * @apiGroup Resources
     * @apiVersion 1.0.0
     *
     * @apiParam {String} name Resource name
     * @apiParam {String} [description] Resource description
     * @apiParam {Boolean} [is_active=true] Active status
     *
     * @apiSuccess {Object} data Created resource
     * @apiSuccess {String} message Success message
     *
     * @apiError {Object} error Error object
     * @apiError {String} error.message Error message
     * @apiError {Object} error.errors Validation errors
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store()
    {
        try {
            $this->debugRequest();
            
            $response = $this->debugPerformance(function () {
                return $this->debugQueries(function () {
                    $request = app($this->formRequest);
                    $validated = $request->validated();

                    $item = $this->model::create($validated);

                    return $this->successResponse(
                        new $this->resource($item),
                        'Resource created successfully',
                        201
                    );
                });
            });

            $this->debugResponse($response);
            return $response;
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display the specified resource.
     *
     * @api {get} /api/{resource}/{id} Get resource details
     * @apiName GetResource
     * @apiGroup Resources
     * @apiVersion 1.0.0
     *
     * @apiParam {Number} id Resource ID
     *
     * @apiSuccess {Object} data Resource details
     *
     * @apiError {Object} error Error object
     * @apiError {String} error.message Error message
     * @apiError {Number} error.code Error code
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $this->debugRequest();
            
            $response = $this->debugPerformance(function () use ($id) {
                return $this->debugQueries(function () use ($id) {
                    $item = $this->model::findOrFail($id);

                    return $this->successResponse(
                        new $this->resource($item),
                        'Resource retrieved successfully'
                    );
                });
            });

            $this->debugResponse($response);
            return $response;
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @api {put} /api/{resource}/{id} Update resource
     * @apiName UpdateResource
     * @apiGroup Resources
     * @apiVersion 1.0.0
     *
     * @apiParam {Number} id Resource ID
     * @apiParam {String} [name] Resource name
     * @apiParam {String} [description] Resource description
     * @apiParam {Boolean} [is_active] Active status
     *
     * @apiSuccess {Object} data Updated resource
     * @apiSuccess {String} message Success message
     *
     * @apiError {Object} error Error object
     * @apiError {String} error.message Error message
     * @apiError {Object} error.errors Validation errors
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id)
    {
        try {
            $this->debugRequest();
            
            $response = $this->debugPerformance(function () use ($id) {
                return $this->debugQueries(function () use ($id) {
                    $item = $this->model::findOrFail($id);
                    $request = app($this->formRequest);
                    $validated = $request->validated();

                    $item->update($validated);

                    return $this->successResponse(
                        new $this->resource($item),
                        'Resource updated successfully'
                    );
                });
            });

            $this->debugResponse($response);
            return $response;
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @api {delete} /api/{resource}/{id} Delete resource
     * @apiName DeleteResource
     * @apiGroup Resources
     * @apiVersion 1.0.0
     *
     * @apiParam {Number} id Resource ID
     *
     * @apiSuccess {String} message Success message
     *
     * @apiError {Object} error Error object
     * @apiError {String} error.message Error message
     * @apiError {Number} error.code Error code
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $this->debugRequest();
            
            $response = $this->debugPerformance(function () use ($id) {
                return $this->debugQueries(function () use ($id) {
                    $item = $this->model::findOrFail($id);
                    $item->delete();

                    return $this->successResponse(
                        null,
                        'Resource deleted successfully'
                    );
                });
            });

            $this->debugResponse($response);
            return $response;
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Handle exceptions in a consistent way
     *
     * @param \Exception $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleException(\Exception $e)
    {
        $this->debug('Exception occurred', [
            'message' => $e->getMessage(),
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
} 