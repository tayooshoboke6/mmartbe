<?php

namespace App\Http\Controllers;

use App\Models\NotificationBar;

class NotificationBarController extends Controller
{
    /**
     * Get the active notification bar for the storefront.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getActive()
    {
        // Get the active notification bar
        $notificationBar = NotificationBar::where('is_active', true)->first();
        
        return response()->json([
            'status' => 'success',
            'notificationBar' => $notificationBar
        ]);
    }
}
