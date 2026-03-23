<?php

namespace App\Http\Controllers;

use App\Services\ProjectFinancialReportService;
use Illuminate\Http\Request;

class ProjectFinancialReportController extends Controller
{
    public function index(Request $request, ProjectFinancialReportService $service)
    {
        $filters = $this->extractFilters($request);
        $report = $service->generate($filters);

        return response()->json($report);
    }

    public function export(Request $request, ProjectFinancialReportService $service)
    {
        $filters = $this->extractFilters($request);

        $format = strtolower((string) $request->query('format', 'excel'));
        if (in_array($format, ['xlsx', 'excel'], true)) {
            $format = 'excel';
        }
        if (!in_array($format, ['excel', 'pdf'], true)) {
            return response()->json(['message' => 'Invalid format. Use format=excel or format=pdf.'], 422);
        }

        $report = $service->generate($filters);
        return $service->export($report, $format);
    }

    private function extractFilters(Request $request): array
    {
        $parseCsv = static function ($value): array {
            if (!$value) {
                return [];
            }
            if (is_array($value)) {
                return array_values(array_filter(array_map('trim', $value), static fn ($v) => $v !== ''));
            }
            return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn ($v) => $v !== ''));
        };

        $divisionValue = $request->query('division_ids');
        if ($divisionValue === null) {
            $divisionValue = $request->query('division');
        }
        $divisionIds = $parseCsv($divisionValue);

        $districtValue = $request->query('district_ids');
        if ($districtValue === null) {
            $districtValue = $request->query('district');
        }
        $districtIds = $parseCsv($districtValue);

        $categoryValue = $request->query('category_ids');
        if ($categoryValue === null) {
            $categoryValue = $request->query('category');
        }
        $categoryIds = $parseCsv($categoryValue);

        $economicCodeValue = $request->query('economic_code_ids');
        if ($economicCodeValue === null) {
            $economicCodeValue = $request->query('economic_code');
        }
        $economicCodeIds = $parseCsv($economicCodeValue);

        return [
            'fiscal_year_id' => (int) $request->query('fiscal_year_id'),
            // quarter: 1..4 or 'all'
            'quarter' => (string) $request->query('quarter', 'all'),
            // If 1 -> show category+economic-code breakdown. Default 0 to match sample output.
            'include_economic_code' => (int) $request->query('include_economic_code', 0),
            'division_ids' => array_map('intval', $divisionIds),
            'district_ids' => array_map('intval', $districtIds),
            'category_ids' => array_map('intval', $categoryIds),
            'economic_code_ids' => array_map('intval', $economicCodeIds),
        ];
    }
}

