<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeDisposition extends Model
{
    protected $table = 'v2_subscribe_dispositions';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'handled_at' => 'integer',
        'expires_at' => 'integer',
        'operator_id' => 'integer',
        'user_id' => 'integer',
    ];
}
