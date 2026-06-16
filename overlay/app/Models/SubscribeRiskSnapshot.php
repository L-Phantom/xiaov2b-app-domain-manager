<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeRiskSnapshot extends Model
{
    protected $table = 'v2_subscribe_risk_snapshots';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_id' => 'integer',
        'risk_score' => 'integer',
        'original_risk_score' => 'integer',
        'request_total' => 'integer',
        'ip_count' => 'integer',
        'host_count' => 'integer',
        'agent_count' => 'integer',
        'traffic_used' => 'integer',
        'traffic_total' => 'integer',
        'first_seen' => 'integer',
        'last_seen' => 'integer',
        'snapshot_at' => 'integer',
        'signals' => 'array',
        'metrics' => 'array',
    ];
}
