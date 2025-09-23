<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inspection extends Model
{
    protected $table = 'inspection';
    public $timestamps = false;

    protected $fillable = [
        'application_id','scheduled_at','inspected_at','inspector_id','notes','photos'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'inspected_at' => 'datetime',
        'photos' => 'array',
    ];
}

