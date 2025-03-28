<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NotificationBar;
use Illuminate\Support\Facades\Validator;

class NotificationBarController extends Controller
{
    /**
     * Get the notification bar.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Get the first notification bar (we only maintain one)
        $notificationBar = NotificationBar::first();
        
        if (!$notificationBar) {
            // Create a default notification bar if none exists
            $notificationBar = NotificationBar::create([
                'message' => 'Free shipping on all orders above ₦5,000',
                'bg_color' => '#0071BC',
                'text_color' => '#FFFFFF',
                'is_active' => true,
            ]);
        }
        
        return response()->json([
            'status' => 'success',
            'notificationBar' => $notificationBar
        ]);
    }
    
    /**
     * Update the notification bar.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:255',
            'bg_color' => 'nullable|string|max:20',
            'text_color' => 'nullable|string|max:20',
            'is_active' => 'nullable|boolean',
            'link' => 'nullable|string|max:255',
            'link_text' => 'nullable|string|max:50',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Get the first notification bar or create one if it doesn't exist
        $notificationBar = NotificationBar::first();
        
        if (!$notificationBar) {
            $notificationBar = new NotificationBar();
        }
        
        // Update notification bar
        $notificationBar->message = $request->message;
        $notificationBar->bg_color = $request->bg_color ?? '#0071BC';
        $notificationBar->text_color = $request->text_color ?? '#FFFFFF';
        $notificationBar->is_active = $request->is_active ?? true;
        $notificationBar->link = $request->link;
        $notificationBar->link_text = $request->link_text;
        $notificationBar->save();
        
        return response()->json([
            'status' => 'success',
            'message' => 'Notification bar updated successfully',
            'notificationBar' => $notificationBar
        ]);
    }
    
    /**
     * Toggle the notification bar status.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleStatus()
    {
        // Get the first notification bar
        $notificationBar = NotificationBar::first();
        
        if (!$notificationBar) {
            // Create a default notification bar if none exists
            $notificationBar = NotificationBar::create([
                'message' => 'Free shipping on all orders above ₦5,000',
                'bg_color' => '#0071BC',
                'text_color' => '#FFFFFF',
                'is_active' => true,
            ]);
        } else {
            // Toggle status
            $notificationBar->is_active = !$notificationBar->is_active;
            $notificationBar->save();
        }
        
        return response()->json([
            'status' => 'success',
            'message' => 'Notification bar status toggled successfully',
            'notificationBar' => $notificationBar
        ]);
    }
}
