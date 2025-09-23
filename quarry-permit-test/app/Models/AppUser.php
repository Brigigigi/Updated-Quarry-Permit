<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUser extends Model
{
    protected $table = 'app_user';
    public $timestamps = true;

    protected $fillable = [
        'email','full_name','org','phone','role','is_active'
    ];
}

