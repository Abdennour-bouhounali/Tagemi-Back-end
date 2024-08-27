<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Type extends Model
{
    use HasFactory;
    protected $fillable = [
        'name_en',
        'name_ar',
        'description_en',
        'description_ar',
        'image_url',
        'statistics'
    ];

    protected $casts = [
        'statistics' => 'array',
    ];
    
    public function activities()
    {
        return $this->hasMany(Activity::class);
    }
}
