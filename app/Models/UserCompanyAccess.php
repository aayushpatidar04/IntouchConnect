<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCompanyAccess extends Model
{
    protected $table = 'user_company_access';

    protected $fillable = [
        'user_id',
        'company_id',
    ];
}

