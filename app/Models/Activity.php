<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

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
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'event_date' => 'date',
        'featured' => 'boolean',
        'participants_count' => 'integer',
        'beneficiaries_count' => 'integer',
    ];

    protected $with = ['media'];

    /**
     * Boot method to automatically set created_by and updated_by
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically set created_by when creating
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
                $model->updated_by = Auth::id();
            }
        });

        // Automatically set updated_by when updating
        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

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
     * Get the user who created the activity.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the activity.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
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