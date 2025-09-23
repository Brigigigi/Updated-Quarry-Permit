<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permit extends Model
{
    protected $table = 'permit';
    public $timestamps = false;

    protected $fillable = [
        'application_id','permit_no','date_approved','term_start','term_end','renewal_count','total_years','grantor_id','pdf_url','qr_hash','status'
    ];
}

