<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'name',
        'subject',
        'body',
    ];

    public function emailLogs()
    {
        return $this->hasMany(EmailLog::class);
    }

    public function getSendStatistics()
    {
        return $this->emailLogs()
            ->selectRaw('email_audience_group_id, COUNT(*) as total_sent, MIN(created_at) as first_sent, MAX(created_at) as last_sent')
            ->groupBy('email_audience_group_id')
            ->with('emailAudienceGroup:id,name')
            ->get();
    }
}
