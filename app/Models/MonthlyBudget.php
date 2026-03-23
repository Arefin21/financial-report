<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyBudget extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'fiscal_month',
        'cost_category_id',
        'economic_code_id',
        'amount',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function costCategory()
    {
        return $this->belongsTo(CostCategory::class);
    }

    public function economicCode()
    {
        return $this->belongsTo(EconomicCode::class);
    }
}
