<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    protected $fillable = [
        'division_id',
        'code',
        'name',
    ];

    public function division()
    {
        return $this->belongsTo(Division::class);
    }
}
