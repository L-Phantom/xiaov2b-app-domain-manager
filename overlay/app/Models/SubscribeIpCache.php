<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeIpCache extends Model
{
    protected $table = 'v2_subscribe_ip_cache';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'ip_version' => 'integer',
        'asn' => 'integer',
        'ip_risk_score' => 'integer',
        'hit' => 'integer',
    ];
}
