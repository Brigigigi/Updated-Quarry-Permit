<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bond extends Model
{
    protected $table = 'bond';
    public $timestamps = false;

    protected $fillable = [
        'application_id','type','amount','issuer','policy_no','issue_date','expiry_date','file_url','status'
    ];
}

