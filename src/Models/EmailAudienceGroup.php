<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAudienceGroup extends Model
{
    protected $fillable = ['name'];

    public function audienceUsers()
    {
        return $this->hasMany(AudienceUser::class);
    }

    public function emailLogs()
    {
        return $this->hasMany(EmailLog::class);
    }

    public function getActiveUsersCountAttribute(): int
    {
        return $this->audienceUsers()->where('is_active', true)->count();
    }

    public function getInactiveUsersCountAttribute(): int
    {
        return $this->audienceUsers()->where('is_active', false)->count();
    }

    public function getSentUsersCountAttribute(): int
    {
        return $this->audienceUsers()->whereNotNull('sent_at')->count();
    }

    public function getBouncedUsersCountAttribute(): int
    {
        return $this->audienceUsers()->where('bounced', true)->count();
    }
}
