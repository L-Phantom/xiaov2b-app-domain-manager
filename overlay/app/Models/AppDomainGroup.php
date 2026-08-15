<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppDomainGroup extends Model
{
    protected $table = 'v2_app_domain_groups';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_group_ids' => 'array',
        'plan_ids' => 'array',
        'hide_matched_nodes' => 'integer',
        'assignment_only' => 'integer',
    ];

    public function bindings()
    {
        return $this->hasMany(AppDomainBinding::class, 'group_id', 'id');
    }
}
