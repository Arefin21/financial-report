<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCategory extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function economicCodes()
    {
        return $this->hasMany(EconomicCode::class);
    }
}
