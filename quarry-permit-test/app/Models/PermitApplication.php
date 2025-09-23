<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermitApplication extends Model
{
    protected $table = 'permit_application';
    public $timestamps = true;

    protected $fillable = [
        'app_no','applicant_id','tracking_id','resource_type','sitio','barangay','municipality','province','island',
        'north_boundary','east_boundary','south_boundary','west_boundary','area_hectares','survey_plan_no',
        'technical_desc_url','ecc_url','epep_url','status','submitted_at'
    ];
}

