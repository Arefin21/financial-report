<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherEntry extends Model
{
    protected $fillable = [
        'voucher_id',
        'cost_category_id',
        'economic_code_id',
        'amount',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
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
