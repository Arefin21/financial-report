<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectFinancialReportController;

Route::get('/', function () {
    return view('welcome');
});

// Project financial reporting (JSON preview)
Route::get('/reports/project-financial', [ProjectFinancialReportController::class, 'index']);

// Project financial reporting (Excel / PDF export)
Route::get('/reports/project-financial/export', [ProjectFinancialReportController::class, 'export']);
