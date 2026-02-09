<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class AudienceUser extends Model
{
    protected $fillable = [
        'name',
        'email',
        'is_active',
        'email_audience_group_id',
        'unsubscribe_token',
        'bounced',
        'bounce_type',
        'bounce_reason',
        'bounced_at',
        'sent_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'bounced' => 'boolean',
        'bounced_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function emailAudienceGroup()
    {
        return $this->belongsTo(EmailAudienceGroup::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    public function scopeByEmailAudienceGroup($query, $groupId)
    {
        return $query->where('email_audience_group_id', $groupId);
    }

    public function scopeNotBounced($query)
    {
        return $query->where('bounced', false);
    }

    public function scopeBounced($query)
    {
        return $query->where('bounced', true);
    }

    public function scopeCanReceiveEmail($query)
    {
        return $query->where('is_active', true)->where('bounced', false);
    }
}
