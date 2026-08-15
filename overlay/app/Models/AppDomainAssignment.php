<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppDomainAssignment extends Model
{
    protected $table = 'v2_app_domain_assignments';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'user_id' => 'integer',
        'group_id' => 'integer',
        'enable' => 'integer',
        'frozen_until' => 'integer',
        'assigned_at' => 'integer',
        'metrics' => 'array',
    ];

    public function group()
    {
        return $this->belongsTo(AppDomainGroup::class, 'group_id', 'id');
    }
}
