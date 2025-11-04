<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityMedia extends Model
{
    use HasFactory;

    protected $table = 'activity_media';

    protected $fillable = [
        'activity_id',
        'url',
        'type',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Get the activity that owns the media.
     */
    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Get the full URL for the media.
     */
    public function getFullUrlAttribute()
    {
        return asset('storage/' . $this->url);
    }
}