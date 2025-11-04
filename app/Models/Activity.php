<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type_id',
        'title_en',
        'title_ar',
        'description_en',
        'description_ar',
        'location',
        'event_date',
        'status',
        'participants_count',
        'beneficiaries_count',
        'featured',
    ];

    protected $casts = [
        'event_date' => 'date',
        'featured' => 'boolean',
        'participants_count' => 'integer',
        'beneficiaries_count' => 'integer',
    ];

    protected $with = ['media'];

    /**
     * Get the type that owns the activity.
     */
    public function type()
    {
        return $this->belongsTo(Type::class);
    }

    /**
     * Get the media for the activity.
     */
    public function media()
    {
        return $this->hasMany(ActivityMedia::class)->orderBy('order');
    }

    /**
     * Scope a query to only include featured activities.
     */
    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    /**
     * Scope a query to filter by status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by type.
     */
    public function scopeByType($query, $typeId)
    {
        return $query->where('type_id', $typeId);
    }
}