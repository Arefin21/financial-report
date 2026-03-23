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
        $parseCsv = static function (?string $value): array {
            if (!$value) {
                return [];
            }
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
        };

        $divisionIds = is_array($request->query('division_ids'))
            ? $request->query('division_ids')
            : $parseCsv($request->query('division_ids'));

        $districtIds = is_array($request->query('district_ids'))
            ? $request->query('district_ids')
            : $parseCsv($request->query('district_ids'));

        $categoryIds = is_array($request->query('category_ids'))
            ? $request->query('category_ids')
            : $parseCsv($request->query('category_ids'));

        $economicCodeIds = is_array($request->query('economic_code_ids'))
            ? $request->query('economic_code_ids')
            : $parseCsv($request->query('economic_code_ids'));

        return [
            'fiscal_year_id' => (int) $request->query('fiscal_year_id'),
            // quarter: 1..4 or 'all'
            'quarter' => (string) $request->query('quarter', 'all'),
            'division_ids' => array_map('intval', (array) $divisionIds),
            'district_ids' => array_map('intval', (array) $districtIds),
            'category_ids' => array_map('intval', (array) $categoryIds),
            'economic_code_ids' => array_map('intval', (array) $economicCodeIds),
        ];
    }
}

