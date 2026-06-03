<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppDomainBinding extends Model
{
    protected $table = 'v2_app_domain_bindings';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'port' => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo(AppDomainGroup::class, 'group_id', 'id');
    }
}
