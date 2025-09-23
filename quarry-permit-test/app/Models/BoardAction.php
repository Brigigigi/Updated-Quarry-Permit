<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardAction extends Model
{
    protected $table = 'board_action';
    public $timestamps = false;

    protected $fillable = [
        'application_id','meeting_no','resolution','minutes_url','decided_at'
    ];
}

