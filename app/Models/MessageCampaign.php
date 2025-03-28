<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'subject',
        'content',
        'user_segment',
        'send_to_email',
        'send_to_inbox',
        'scheduled_date',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'send_to_email' => 'boolean',
        'send_to_inbox' => 'boolean',
        'scheduled_date' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user notifications associated with this campaign
     */
    public function userNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }
    
    /**
     * Scope a query to only include draft campaigns.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
    
    /**
     * Scope a query to only include scheduled campaigns.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }
    
    /**
     * Scope a query to only include sent campaigns.
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }
    
    /**
     * Scope a query to only include cancelled campaigns.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }
    
    /**
     * Scope a query to only include campaigns that need to be sent now.
     */
    public function scopeReadyToSend($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_date', '<=', now());
    }
}
