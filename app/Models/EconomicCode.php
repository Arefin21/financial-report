<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EconomicCode extends Model
{
    protected $fillable = [
        'cost_category_id',
        'code',
        'name',
    ];

    public function costCategory()
    {
        return $this->belongsTo(CostCategory::class);
    }
}
