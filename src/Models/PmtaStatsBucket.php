<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class PmtaStatsBucket extends Model
{
    protected $table = 'pmta_stats_buckets';

    protected $fillable = [
        'server',
        'granularity',
        'bucket_start',
        'delivered',
        'bounced_stop',
        'bounced_go',
        'domains',
        'ips',
    ];

    protected $casts = [
        'bucket_start' => 'datetime',
        'delivered' => 'integer',
        'bounced_stop' => 'integer',
        'bounced_go' => 'integer',
        'domains' => 'array',
        'ips' => 'array',
    ];
}
