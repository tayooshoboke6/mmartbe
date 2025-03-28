<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageCampaign;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MessageCampaignController extends Controller
{
    /**
     * Display a listing of message campaigns.
     */
    public function index()
    {
        try {
            $campaigns = MessageCampaign::orderBy('created_at', 'desc')->get();
            
            // Transform data to match frontend expectations
            $transformedCampaigns = $campaigns->map(function ($campaign) {
                return $this->transformCampaign($campaign);
            });
            
            return response()->json([
                'status' => 'success',
                'data' => $transformedCampaigns
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching message campaigns: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch message campaigns'
            ], 500);
        }
    }

    /**
     * Store a newly created message campaign.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'recipients' => 'required|array',
            'recipients.userSegment' => 'required|string|max:50',
            'sendToEmail' => 'boolean',
            'sendToInbox' => 'boolean',
            'scheduledDate' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $status = 'draft';
            if ($request->scheduledDate) {
                $scheduledDate = new \DateTime($request->scheduledDate);
                $now = new \DateTime();
                
                if ($scheduledDate <= $now) {
                    // If scheduled date is in the past or now, set to send immediately
                    $status = 'sent';
                } else {
                    $status = 'scheduled';
                }
            }

            // Extract user segment from recipients
            $userSegment = $request->recipients['userSegment'] ?? 'all';

            $campaign = MessageCampaign::create([
                'title' => $request->title,
                'subject' => $request->subject,
                'content' => $request->content,
                'user_segment' => $userSegment,
                'send_to_email' => $request->sendToEmail ?? false,
                'send_to_inbox' => $request->sendToInbox ?? true,
                'scheduled_date' => $request->scheduledDate,
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);

            // If the campaign is set to be sent immediately, process it
            if ($status === 'sent') {
                $this->processCampaign($campaign);
            }

            // Transform data to match frontend expectations
            $transformedCampaign = $this->transformCampaign($campaign);

            return response()->json([
                'status' => 'success',
                'message' => 'Message campaign created successfully',
                'data' => $transformedCampaign
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating message campaign: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create message campaign: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified message campaign.
     */
    public function show($id)
    {
        try {
            $campaign = MessageCampaign::findOrFail($id);
            
            // Transform data to match frontend expectations
            $transformedCampaign = $this->transformCampaign($campaign);
            
            return response()->json([
                'status' => 'success',
                'data' => $transformedCampaign
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching message campaign: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Message campaign not found'
            ], 404);
        }
    }

    /**
     * Update the specified message campaign.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'subject' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'recipients' => 'sometimes|required|array',
            'recipients.userSegment' => 'sometimes|required|string|max:50',
            'sendToEmail' => 'sometimes|boolean',
            'sendToInbox' => 'sometimes|boolean',
            'scheduledDate' => 'nullable|date',
            'status' => 'sometimes|in:draft,scheduled,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $campaign = MessageCampaign::findOrFail($id);
            
            // Cannot update a campaign that has already been sent
            if ($campaign->status === 'sent') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot update a campaign that has already been sent'
                ], 422);
            }

            $status = $request->status ?? $campaign->status;
            
            // If scheduled date is provided, update the status accordingly
            if ($request->has('scheduledDate')) {
                if ($request->scheduledDate) {
                    $scheduledDate = new \DateTime($request->scheduledDate);
                    $now = new \DateTime();
                    
                    if ($scheduledDate <= $now) {
                        // If scheduled date is in the past or now, set to send immediately
                        $status = 'sent';
                    } else {
                        $status = 'scheduled';
                    }
                } else {
                    // If scheduled date is removed, set to draft
                    $status = 'draft';
                }
            }

            // Extract user segment from recipients if provided
            $userSegment = $campaign->user_segment;
            if ($request->has('recipients') && isset($request->recipients['userSegment'])) {
                $userSegment = $request->recipients['userSegment'];
            }

            $campaign->update([
                'title' => $request->title ?? $campaign->title,
                'subject' => $request->subject ?? $campaign->subject,
                'content' => $request->content ?? $campaign->content,
                'user_segment' => $userSegment,
                'send_to_email' => $request->has('sendToEmail') ? $request->sendToEmail : $campaign->send_to_email,
                'send_to_inbox' => $request->has('sendToInbox') ? $request->sendToInbox : $campaign->send_to_inbox,
                'scheduled_date' => $request->has('scheduledDate') ? $request->scheduledDate : $campaign->scheduled_date,
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);

            // If the campaign is set to be sent immediately, process it
            if ($status === 'sent' && $campaign->status !== 'sent') {
                $this->processCampaign($campaign);
            }

            // Transform data to match frontend expectations
            $transformedCampaign = $this->transformCampaign($campaign);

            return response()->json([
                'status' => 'success',
                'message' => 'Message campaign updated successfully',
                'data' => $transformedCampaign
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating message campaign: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update message campaign: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified message campaign.
     */
    public function destroy($id)
    {
        try {
            $campaign = MessageCampaign::findOrFail($id);
            
            // Cannot delete a campaign that has already been sent
            if ($campaign->status === 'sent') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete a campaign that has already been sent'
                ], 422);
            }
            
            $campaign->delete();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message campaign deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting message campaign: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete message campaign: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send the specified message campaign immediately.
     */
    public function send($id)
    {
        try {
            $campaign = MessageCampaign::findOrFail($id);
            
            // Cannot send a campaign that has already been sent
            if ($campaign->status === 'sent') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Campaign has already been sent'
                ], 422);
            }
            
            // Update campaign status and sent_at timestamp
            $campaign->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
            
            // Process the campaign (create notifications for users)
            $this->processCampaign($campaign);
            
            // Transform data to match frontend expectations
            $transformedCampaign = $this->transformCampaign($campaign);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Message campaign sent successfully',
                'data' => $transformedCampaign
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending message campaign: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send message campaign: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available user segments for message campaigns.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSegments()
    {
        try {
            // Define standard user segments
            $segments = [
                [
                    'id' => '1',
                    'userSegment' => 'all',
                    'name' => 'All Users'
                ],
                [
                    'id' => '2',
                    'userSegment' => 'premium',
                    'name' => 'Premium Users'
                ],
                [
                    'id' => '3',
                    'userSegment' => 'new_users',
                    'name' => 'New Users (Last 30 Days)'
                ],
                [
                    'id' => '4',
                    'userSegment' => 'inactive',
                    'name' => 'Inactive Users (90+ Days)'
                ],
                [
                    'id' => '5',
                    'userSegment' => 'frequent_shoppers',
                    'name' => 'Frequent Shoppers'
                ]
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $segments
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user segments: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user segments'
            ], 500);
        }
    }

    /**
     * Get available user segments.
     */
    public function getUserSegments()
    {
        try {
            // Define available user segments
            $segmentNames = [
                'all',
                'premium',
                'new_users',
                'inactive',
                'frequent_shoppers',
            ];
            
            // Transform segments to match frontend expectations
            $segments = array_map(function($segment) {
                return [
                    'id' => 'segment-' . $segment,
                    'name' => ucwords(str_replace('_', ' ', $segment)),
                    'userSegment' => $segment,
                    'all' => $segment === 'all'
                ];
            }, $segmentNames);
            
            return response()->json([
                'status' => 'success',
                'data' => $segments
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user segments: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user segments'
            ], 500);
        }
    }

    /**
     * Helper method to transform campaign data to frontend format
     */
    private function transformCampaign($campaign)
    {
        return [
            'id' => (string) $campaign->id,
            'title' => $campaign->title,
            'subject' => $campaign->subject,
            'content' => $campaign->content,
            'recipients' => [
                'id' => 'segment-' . $campaign->user_segment,
                'userSegment' => $campaign->user_segment,
                'all' => $campaign->user_segment === 'all'
            ],
            'sendToEmail' => (bool) $campaign->send_to_email,
            'sendToInbox' => (bool) $campaign->send_to_inbox,
            'scheduledDate' => $campaign->scheduled_date ? $campaign->scheduled_date->toIso8601String() : null,
            'status' => $campaign->status,
            'sentAt' => $campaign->sent_at ? $campaign->sent_at->toIso8601String() : null,
            'createdAt' => $campaign->created_at->toIso8601String(),
            'updatedAt' => $campaign->updated_at->toIso8601String()
        ];
    }

    /**
     * Process a campaign by creating notifications for target users.
     */
    private function processCampaign(MessageCampaign $campaign)
    {
        try {
            DB::beginTransaction();
            
            // Get target users based on the segment
            $users = $this->getUsersBySegment($campaign->user_segment);
            
            // Create notifications for each user
            foreach ($users as $user) {
                UserNotification::create([
                    'user_id' => $user->id,
                    'message_campaign_id' => $campaign->id,
                    'title' => $campaign->subject,
                    'content' => $campaign->content,
                    'type' => 'campaign',
                    'is_read' => false,
                    'data' => [
                        'campaign_id' => $campaign->id,
                        'campaign_title' => $campaign->title,
                    ],
                ]);
                
                // TODO: If send_to_email is true, queue an email to be sent to the user
                if ($campaign->send_to_email) {
                    // Queue email sending logic here
                    // Mail::to($user->email)->queue(new CampaignEmail($campaign));
                }
            }
            
            DB::commit();
            
            // Log successful campaign processing
            Log::info("Campaign {$campaign->id} processed successfully for {$users->count()} users");
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing campaign {$campaign->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get users based on the specified segment.
     */
    private function getUsersBySegment($segment)
    {
        switch ($segment) {
            case 'all':
                return User::where('role', '!=', 'admin')->get();
                
            case 'premium':
                // Users who have made purchases over a certain amount
                return User::whereHas('orders', function ($query) {
                    $query->where('total', '>=', 500);
                })->get();
                
            case 'new_users':
                // Users who registered in the last 30 days
                return User::where('created_at', '>=', now()->subDays(30))->get();
                
            case 'inactive':
                // Users who haven't placed an order in the last 60 days
                return User::whereDoesntHave('orders', function ($query) {
                    $query->where('created_at', '>=', now()->subDays(60));
                })->get();
                
            case 'frequent_shoppers':
                // Users who have placed more than 3 orders
                return User::whereHas('orders', function ($query) {
                    $query->selectRaw('COUNT(*) as order_count')
                        ->groupBy('user_id')
                        ->havingRaw('COUNT(*) > 3');
                })->get();
                
            default:
                return User::where('role', '!=', 'admin')->get();
        }
    }
}
