<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectImage extends Model
{
    use HasFactory;

    // Table name (if it doesn't follow Laravel's plural naming convention)
    // protected $table = 'project_images';  // Use this if your table name is not plural

    // The attributes that are mass assignable.
    protected $fillable = [
        'projectId',
        'imageUrl',
    ];

    // Define the relationship with the FutureProject model
    public function futureProject()
    {
        return $this->belongsTo(FutureProject::class, 'projectId', 'id'); // Ensure 'id' is the primary key in FutureProject
    }
}
