<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Division extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function districts()
    {
        return $this->hasMany(District::class);
    }
}
