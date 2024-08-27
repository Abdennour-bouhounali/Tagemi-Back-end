<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FutureProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_en', 'title_ar', 'description_en', 'description_ar',
    ];

    public function projectImages()
    {
        return $this->hasMany(ProjectImage::class, 'projectId', 'id'); // Ensure 'id' is the primary key in FutureProject
    }
}
