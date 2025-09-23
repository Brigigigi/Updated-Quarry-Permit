<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeAssessment extends Model
{
    protected $table = 'fee_assessment';
    public $timestamps = false;

    protected $fillable = [
        'application_id','items_json','total_amount','or_no','notes','created_by','created_at'
    ];

    protected $casts = [
        'items_json' => 'array',
        'created_at' => 'datetime',
    ];
}

