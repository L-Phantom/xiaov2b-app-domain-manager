<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppDomainRule extends Model
{
    protected $table = 'v2_app_domain_rules';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_group_ids' => 'array',
        'plan_ids' => 'array',
        'server_types' => 'array',
        'server_ids' => 'array',
        'protocols' => 'array',
    ];
}
