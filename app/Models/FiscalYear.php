<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = [
        'label',
        'start_year',
        'start_date',
        'end_date',
    ];

    public function monthlyBudgets()
    {
        return $this->hasMany(MonthlyBudget::class);
    }
}
