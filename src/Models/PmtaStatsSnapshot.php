<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class PmtaStatsSnapshot extends Model
{
    protected $table = 'pmta_stats_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'server',
        'period_days',
        'snapshot_at',
        'delivered',
        'bounced_stop',
        'bounced_go',
        'domains',
        'ips',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'period_days' => 'integer',
        'delivered' => 'integer',
        'bounced_stop' => 'integer',
        'bounced_go' => 'integer',
        'domains' => 'array',
        'ips' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
    }
}
