<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;
    protected $fillable = [
        'title_en', 'title_ar', 'description_en', 'description_ar', 'type_id', 'date',

        'featured'
    ];

    public function type()
    {
        return $this->belongsTo(Type::class);
    }

    public function media()
    {
        return $this->hasMany(Media::class);
    }
}
