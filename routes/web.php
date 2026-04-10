<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectFinancialReportController;

// Project Financial Report (main app page)
Route::get('/', [ProjectFinancialReportController::class, 'ui']);

// Old UI URL → home (bookmarks still work)
//Route::redirect('/reports/project-financial/ui', '/');

// Project financial reporting (JSON preview)
Route::get('/reports/project-financial', [ProjectFinancialReportController::class, 'index']);

// Project financial reporting (Excel / PDF export)
Route::get('/reports/project-financial/export', [ProjectFinancialReportController::class, 'export']);
