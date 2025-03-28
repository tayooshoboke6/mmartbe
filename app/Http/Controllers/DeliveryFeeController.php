<?php

namespace App\Http\Controllers;

use App\Services\DeliveryFeeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeliveryFeeController extends Controller
{
    protected $deliveryFeeService;

    /**
     * Create a new controller instance.
     *
     * @param DeliveryFeeService $deliveryFeeService
     * @return void
     */
    public function __construct(DeliveryFeeService $deliveryFeeService)
    {
        $this->deliveryFeeService = $deliveryFeeService;
    }

    /**
     * Calculate delivery fee based on location and order subtotal
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function calculate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'subtotal' => 'required|numeric|min:0',
            'store_id' => 'nullable|integer|exists:store_addresses,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid input data',
                'errors' => $validator->errors()
            ], 422);
        }

        $customerLocation = [
            $request->latitude,
            $request->longitude
        ];

        $result = $this->deliveryFeeService->calculateDeliveryFee(
            $request->subtotal,
            $customerLocation,
            $request->store_id
        );

        return response()->json([
            'status' => 'success',
            'data' => $result
        ]);
    }
}
