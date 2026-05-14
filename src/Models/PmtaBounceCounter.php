<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

class PmtaBounceCounter extends Model
{
    protected $fillable = [
        'server',
        'bounce_cat',
        'counter_hour',
        'count',
    ];

    protected $casts = [
        'counter_hour' => 'datetime',
        'count' => 'integer',
    ];
}
