<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CouponController extends Controller
{
    /**
     * Display a listing of the coupons (admin only).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Coupon::with(['categories', 'products'])
            ->when($request->has('active_only'), function ($q) use ($request) {
                if ($request->boolean('active_only')) {
                    return $q->where('is_active', true);
                }
                return $q;
            })
            ->when($request->has('valid_only'), function ($q) use ($request) {
                if ($request->boolean('valid_only')) {
                    return $q->valid();
                }
                return $q;
            })
            ->when($request->has('search'), function ($q) use ($request) {
                return $q->where('code', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        
        $coupons = $query->orderBy($request->sort_by ?? 'created_at', $request->sort_direction ?? 'desc')
            ->paginate($request->per_page ?? 15);
        
        return response()->json($coupons);
    }

    /**
     * Store a newly created coupon in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:coupons',
            'type' => 'required|string|in:fixed,percentage',
            'value' => 'required|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'usage_limit' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'description' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Validate percentage value
        if ($request->type === Coupon::TYPE_PERCENTAGE && $request->value > 100) {
            return response()->json([
                'message' => 'Percentage value cannot exceed 100',
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            $coupon = Coupon::create([
                'code' => strtoupper($request->code),
                'type' => $request->type,
                'value' => $request->value,
                'min_order_amount' => $request->min_order_amount,
                'max_discount_amount' => $request->max_discount_amount,
                'starts_at' => $request->starts_at,
                'expires_at' => $request->expires_at,
                'usage_limit' => $request->usage_limit,
                'used_count' => 0,
                'is_active' => $request->boolean('is_active', true),
                'description' => $request->description,
            ]);
            
            // Attach categories if provided
            if ($request->has('category_ids')) {
                $coupon->categories()->attach($request->category_ids);
            }
            
            // Attach products if provided
            if ($request->has('product_ids')) {
                $coupon->products()->attach($request->product_ids);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Coupon created successfully',
                'coupon' => $coupon->fresh(['categories', 'products']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create coupon: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified coupon in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $coupon = Coupon::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:50|unique:coupons,code,' . $coupon->id,
            'type' => 'sometimes|string|in:fixed,percentage',
            'value' => 'sometimes|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'usage_limit' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'description' => 'nullable|string',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Validate percentage value
        if ($request->has('type') && $request->type === Coupon::TYPE_PERCENTAGE && $request->has('value') && $request->value > 100) {
            return response()->json([
                'message' => 'Percentage value cannot exceed 100',
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            // Update coupon
            $coupon->update([
                'code' => $request->has('code') ? strtoupper($request->code) : $coupon->code,
                'type' => $request->type ?? $coupon->type,
                'value' => $request->value ?? $coupon->value,
                'min_order_amount' => $request->min_order_amount,
                'max_discount_amount' => $request->max_discount_amount,
                'starts_at' => $request->starts_at,
                'expires_at' => $request->expires_at,
                'usage_limit' => $request->usage_limit,
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $coupon->is_active,
                'description' => $request->description ?? $coupon->description,
            ]);
            
            // Update categories if provided
            if ($request->has('category_ids')) {
                $coupon->categories()->sync($request->category_ids);
            }
            
            // Update products if provided
            if ($request->has('product_ids')) {
                $coupon->products()->sync($request->product_ids);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Coupon updated successfully',
                'coupon' => $coupon->fresh(['categories', 'products']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update coupon: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified coupon from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $coupon = Coupon::findOrFail($id);
        
        // Check if coupon has been used in orders
        if ($coupon->orders()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete coupon that has been used in orders',
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            // Detach all relationships
            $coupon->categories()->detach();
            $coupon->products()->detach();
            
            // Delete coupon
            $coupon->delete();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Coupon deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to delete coupon: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Validate a coupon code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function validateCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'order_amount' => 'required|numeric|min:0',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $coupon = Coupon::where('code', strtoupper($request->code))->first();
        
        if (!$coupon) {
            return response()->json([
                'valid' => false,
                'message' => 'Coupon not found',
            ]);
        }
        
        $userId = $request->user() ? $request->user()->id : null;
        $isValid = $coupon->isValid($request->order_amount, $userId);
        
        if (!$isValid) {
            $message = 'Coupon is not valid';
            
            if (!$coupon->is_active) {
                $message = 'Coupon is inactive';
            } elseif ($coupon->starts_at && Carbon::now()->lt($coupon->starts_at)) {
                $message = 'Coupon is not yet active';
            } elseif ($coupon->expires_at && Carbon::now()->gt($coupon->expires_at)) {
                $message = 'Coupon has expired';
            } elseif ($coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit) {
                $message = 'Coupon usage limit reached';
            } elseif ($coupon->min_order_amount && $request->order_amount < $coupon->min_order_amount) {
                $message = 'Order amount does not meet minimum requirement';
            } elseif ($userId && $coupon->hasUserUsedCoupon($userId)) {
                $message = 'You have already used this coupon';
            }
            
            return response()->json([
                'valid' => false,
                'message' => $message,
            ]);
        }
        
        $discountAmount = $coupon->calculateDiscount($request->order_amount);
        
        return response()->json([
            'valid' => true,
            'coupon' => $coupon,
            'discount_amount' => $discountAmount,
            'message' => 'Coupon is valid',
        ]);
    }

    /**
     * Toggle the active status of a coupon.
     *
     * @param  int  $coupon
     * @return \Illuminate\Http\Response
     */
    public function toggleStatus($coupon)
    {
        try {
            $coupon = Coupon::findOrFail($coupon);
            $coupon->is_active = !$coupon->is_active;
            $coupon->save();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Coupon status toggled successfully',
                'data' => $coupon->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle coupon status: ' . $e->getMessage()
            ], 500);
        }
    }
}
