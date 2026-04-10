<?php

namespace App\Http\Controllers;

use App\Models\CostCategory;
use App\Models\District;
use App\Models\Division;
use App\Models\EconomicCode;
use App\Models\FiscalYear;
use App\Services\ProjectFinancialReportService;
use Illuminate\Http\Request;

class ProjectFinancialReportController extends Controller
{
    /**
     * Report UI: load dropdown data and return the Blade view.
     */
    public function ui()
    {
        return view('reports.project-financial', $this->reportFilterOptions());
    }

    /**
     * JSON API: same report data the UI uses.
     */
    public function index(Request $request, ProjectFinancialReportService $service)
    {
        $report = $service->generate($this->filtersFromRequest($request));

        return response()->json($report);
    }

    /**
     * Download Excel or PDF for the same filters as the JSON endpoint.
     */
    public function export(Request $request, ProjectFinancialReportService $service)
    {
        $format = $this->normalizeExportFormat($request->query('format', 'excel'));

        if ($format === null) {
            return response()->json([
                'message' => 'Invalid format. Use format=excel or format=pdf.',
            ], 422);
        }

        $report = $service->generate($this->filtersFromRequest($request));

        return $service->export($report, $format);
    }

    /**
     * Everything the report form needs (fiscal years, regions, categories, codes).
     */
    private function reportFilterOptions(): array
    {
        $fiscalYears = FiscalYear::query()
            ->orderByDesc('start_date')
            ->get(['id', 'label']);

        $divisions = Division::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $districts = District::query()
            ->orderBy('name')
            ->get(['id', 'division_id', 'code', 'name']);

        $categories = CostCategory::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $economicCodes = EconomicCode::query()
            ->orderBy('code')
            ->get(['id', 'cost_category_id', 'code', 'name']);

        return compact('fiscalYears', 'divisions', 'districts', 'categories', 'economicCodes');
    }

    /**
     * Read query string parameters into the array shape ProjectFinancialReportService expects.
     */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'fiscal_year_id' => (int) $request->query('fiscal_year_id'),
            'quarter' => (string) $request->query('quarter', 'all'),
            'include_economic_code' => (int) $request->query('include_economic_code', 0),
            'division_ids' => $this->intIdsFromQuery($request, 'division_ids', 'division'),
            'district_ids' => $this->intIdsFromQuery($request, 'district_ids', 'district'),
            'category_ids' => $this->intIdsFromQuery($request, 'category_ids', 'category'),
            'economic_code_ids' => $this->intIdsFromQuery($request, 'economic_code_ids', 'economic_code'),
        ];
    }

    /**
     * Accepts either ?division_ids=1,2 or ?division[]=1&division[]=2 (comma or array).
     */
    private function intIdsFromQuery(Request $request, string $primaryKey, string $alternateKey): array
    {
        $raw = $request->query($primaryKey);
        if ($raw === null) {
            $raw = $request->query($alternateKey);
        }

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            $parts = array_map('trim', $raw);
        } else {
            $parts = array_map('trim', explode(',', (string) $raw));
        }

        $parts = array_filter($parts, fn ($v) => $v !== '');

        return array_map('intval', array_values($parts));
    }

    /**
     * @return 'excel'|'pdf'|null
     */
    private function normalizeExportFormat(mixed $format): ?string
    {
        $format = strtolower((string) $format);

        if (in_array($format, ['xlsx', 'excel'], true)) {
            return 'excel';
        }

        if ($format === 'pdf') {
            return 'pdf';
        }

        return null;
    }
}
