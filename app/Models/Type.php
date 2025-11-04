<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Type extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name_en',
        'name_ar',
        'description_en',
        'description_ar',
        'image_url',
        'order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the activities for the type.
     */
    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Scope a query to only include active types.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Get activities count for this type.
     */
    public function getActivitiesCountAttribute()
    {
        return $this->activities()->count();
    }
}