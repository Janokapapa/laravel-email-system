<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class BouncedEmail extends Model
{
    protected $fillable = [
        'email',
        'bounce_type',
        'bounce_reason',
        'source',
        'pmta_server',
        'source_domain',
        'bounced_at',
    ];

    protected $casts = [
        'bounced_at' => 'datetime',
    ];
}
