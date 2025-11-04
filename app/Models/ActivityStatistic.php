<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityStatistic extends Model
{
    protected $fillable = [
        'activity_id',
        'stat_key',
        'stat_value',
        'stat_label_en',
        'stat_label_ar'
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}