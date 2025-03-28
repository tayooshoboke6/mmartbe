<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserNotificationController extends Controller
{
    /**
     * Display a listing of the user's notifications.
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $perPage = $request->input('per_page', 10);
            $isRead = $request->input('is_read');
            
            $query = UserNotification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc');
            
            // Filter by read status if provided
            if ($isRead !== null) {
                $query->where('is_read', $isRead === 'true' || $isRead === '1');
            }
            
            $notifications = $query->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $notifications
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user notifications: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch notifications'
            ], 500);
        }
    }

    /**
     * Display the specified notification.
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $notification = UserNotification::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();
            
            return response()->json([
                'status' => 'success',
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching notification: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Notification not found'
            ], 404);
        }
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead($id)
    {
        try {
            $user = Auth::user();
            $notification = UserNotification::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();
            
            $notification->markAsRead();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Notification marked as read',
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking notification as read: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark notification as read'
            ], 500);
        }
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        try {
            $user = Auth::user();
            $count = UserNotification::where('user_id', $user->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);
            
            return response()->json([
                'status' => 'success',
                'message' => "{$count} notifications marked as read"
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking all notifications as read: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark notifications as read'
            ], 500);
        }
    }

    /**
     * Delete a notification.
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            $notification = UserNotification::where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();
            
            $notification->delete();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting notification: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete notification'
            ], 500);
        }
    }

    /**
     * Get unread notification count.
     */
    public function getUnreadCount()
    {
        try {
            $user = Auth::user();
            $count = UserNotification::where('user_id', $user->id)
                ->where('is_read', false)
                ->count();
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'count' => $count
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting unread notification count: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get unread notification count'
            ], 500);
        }
    }
}
