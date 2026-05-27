<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Todo extends Model
{
    protected $fillable = [
        'title', 'notes', 'status', 'priority',
        'category', 'progress', 'deadline', 'is_completed'
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'deadline' => 'datetime',
    ];
}