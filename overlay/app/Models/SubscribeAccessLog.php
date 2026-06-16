<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeAccessLog extends Model
{
    protected $table = 'v2_subscribe_access_logs';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_id' => 'integer',
        'plan_id' => 'integer',
        'traffic_used' => 'integer',
        'traffic_total' => 'integer',
        'expired_at' => 'integer',
        'status' => 'integer',
    ];
}
