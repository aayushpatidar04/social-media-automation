<?php

// app/Models/AnalyticsCache.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsCache extends Model
{
    protected $fillable = [
        'organization_id',
        'metric_type',
        'data',
        'last_updated_at'
    ];

    protected $casts = [
        'data' => 'array',
        'last_updated_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
