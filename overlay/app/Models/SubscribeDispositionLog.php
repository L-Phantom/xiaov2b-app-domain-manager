<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeDispositionLog extends Model
{
    protected $table = 'v2_subscribe_disposition_logs';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'integer',
        'operator_id' => 'integer',
        'user_id' => 'integer',
        'risk_score' => 'integer',
    ];
}
