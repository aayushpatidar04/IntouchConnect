<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = ['name', 'description', 'created_by'];

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_group');
    }


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

