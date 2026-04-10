<?php

namespace App\Services;

use App\Models\CostCategory;
use App\Models\EconomicCode;
use App\Models\FiscalYear;
use App\Models\MonthlyBudget;
use App\Models\VoucherEntry;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProjectFinancialReportService
{
    /**
     * Build the report array: meta, rows, totals.
     */
    public function generate(array $filters): array
    {
        $quarterKey = strtolower(trim((string) ($filters['quarter'] ?? 'all')));
        $fiscalYear = $this->loadFiscalYear($filters);
        $includeEconomicCode = ((int) ($filters['include_economic_code'] ?? 0)) === 1;

        $divisionIds = array_values(array_filter($filters['division_ids'] ?? []));
        $districtIds = array_values(array_filter($filters['district_ids'] ?? []));
        $categoryIds = array_values(array_filter($filters['category_ids'] ?? []));
        $economicCodeIds = array_values(array_filter($filters['economic_code_ids'] ?? []));

        $period = $this->dateRangeForQuarter($fiscalYear, $quarterKey);

        $categories = $this->loadCategories($categoryIds);
        $categoryById = $categories->keyBy('id');

        if ($includeEconomicCode) {
            $result = $this->buildRowsPerEconomicCode(
                $fiscalYear,
                $period,
                $categoryById,
                $divisionIds,
                $districtIds,
                $categoryIds,
                $economicCodeIds
            );
        } else {
            $result = $this->buildRowsPerCategoryOnly(
                $fiscalYear,
                $period,
                $categories,
                $divisionIds,
                $districtIds,
                $categoryIds,
                $economicCodeIds
            );
        }

        $rows = $result['rows'];
        $totalExpensesQuarter = $result['total_expenses_quarter'];
        $totalBudgetQuarter = $result['total_budget_quarter'];
        $totalBudgetAnnual = $result['total_budget_annual'];

        $totalPctQuarter = $this->percentOf($totalExpensesQuarter, $totalBudgetQuarter);
        $totalPctAnnual = $this->percentOf($totalExpensesQuarter, $totalBudgetAnnual);

        return [
            'meta' => [
                'fiscal_year' => $fiscalYear->label,
                'quarter' => $quarterKey,
                'quarter_start' => $period['start']->toDateString(),
                'quarter_end' => $period['end']->toDateString(),
                'include_economic_code' => $includeEconomicCode ? 1 : 0,
            ],
            'rows' => $rows,
            'totals' => [
                'total_expenses_quarter' => round($totalExpensesQuarter, 2),
                'total_budget_quarter' => round($totalBudgetQuarter, 2),
                'expenses_vs_budget_quarter_pct' => round($totalPctQuarter, 2),
                'total_budget_annual' => round($totalBudgetAnnual, 2),
                'expenses_vs_budget_annual_pct' => round($totalPctAnnual, 2),
            ],
        ];
    }

    /**
     * Use requested fiscal year, or the latest one if none was sent.
     */

    private function loadFiscalYear(array $filters): FiscalYear
    {
        $id = (int) ($filters['fiscal_year_id'] ?? 0);

        if ($id <= 0) {
            $latestId = FiscalYear::query()->orderByDesc('id')->value('id');
            if (!$latestId) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => ['fiscal_year_id is required.'],
                ]);
            }
            $id = (int) $latestId;
        }

        return FiscalYear::query()->findOrFail($id);
    }

    /**
     * For the selected quarter (or "all"), return start/end dates and fiscal month range (1–12).
     *
     * @return array{start: Carbon, end: Carbon, fiscal_month_start: int, fiscal_month_end: int}
     */
    private function dateRangeForQuarter(FiscalYear $fiscalYear, string $quarter): array
    {
        $fyStart = Carbon::parse($fiscalYear->start_date);
        $fyEnd = Carbon::parse($fiscalYear->end_date);

        if ($quarter === 'all') {
            return [
                'start' => $fyStart->copy(),
                'end' => $fyEnd->copy(),
                'fiscal_month_start' => 1,
                'fiscal_month_end' => 12,
            ];
        }

        if (!in_array($quarter, ['1', '2', '3', '4'], true)) {
            throw new \InvalidArgumentException("quarter must be 1..4 or 'all'. Got: {$quarter}");
        }

        $q = (int) $quarter;
        $quarterStart = $fyStart->copy()->addMonths(($q - 1) * 3);
        $quarterEnd = $quarterStart->copy()->addMonths(2)->endOfMonth();

        return [
            'start' => $quarterStart,
            'end' => $quarterEnd,
            'fiscal_month_start' => ($q - 1) * 3 + 1,
            'fiscal_month_end' => $q * 3,
        ];
        
    }

    private function loadCategories(array $categoryIds)
    {
        $query = CostCategory::query()->orderBy('code');

        if (!empty($categoryIds)) {
            $query->whereIn('id', $categoryIds);
        }

        return $query->get(['id', 'code', 'name']);
    }

    /**
     * One row per economic code (with category columns).
     */
    private function buildRowsPerEconomicCode(
        FiscalYear $fiscalYear,
        array $period,
        $categoryById,
        array $divisionIds,
        array $districtIds,
        array $categoryIds,
        array $economicCodeIds
    ): array {
        $economicCodesQuery = EconomicCode::query()
            ->select(['id', 'cost_category_id', 'code', 'name']);

        if (!empty($categoryIds)) {
            $economicCodesQuery->whereIn('cost_category_id', $categoryIds);
        }
        if (!empty($economicCodeIds)) {
            $economicCodesQuery->whereIn('id', $economicCodeIds);
        }

        $economicCodes = $economicCodesQuery
            ->orderBy('cost_category_id')
            ->orderBy('code')
            ->get();

        $fyId = $fiscalYear->id;
        $mStart = $period['fiscal_month_start'];
        $mEnd = $period['fiscal_month_end'];

        $budgetQuarter = $this->sumBudgetByCategoryAndEconomicCode($fyId, $mStart, $mEnd, $categoryIds, $economicCodeIds);
        $budgetAnnual = $this->sumBudgetByCategoryAndEconomicCode($fyId, 1, 12, $categoryIds, $economicCodeIds);
        $expensesQuarter = $this->sumExpensesByCategoryAndEconomicCode(
            $period['start'],
            $period['end'],
            $divisionIds,
            $districtIds,
            $categoryIds,
            $economicCodeIds
        );

        $rows = [];
        $totalExpensesQuarter = 0.0;
        $totalBudgetQuarter = 0.0;
        $totalBudgetAnnual = 0.0;

        foreach ($economicCodes as $econ) {
            $catId = (int) $econ->cost_category_id;
            $category = $categoryById->get($catId);
            if (!$category) {
                continue;
            }

            $spent = (float) ($expensesQuarter[$catId][$econ->id] ?? 0.0);
            $budgetQ = (float) ($budgetQuarter[$catId][$econ->id] ?? 0.0);
            $budgetY = (float) ($budgetAnnual[$catId][$econ->id] ?? 0.0);

            $rows[] = [
                'cost_category_id' => $category->id,
                'cost_category_code' => $category->code,
                'cost_category_name' => $category->name,
                'economic_code_id' => $econ->id,
                'economic_code_code' => $econ->code,
                'economic_code_name' => $econ->name,
                'expenses_quarter' => $spent,
                'budget_quarter' => $budgetQ,
                'expenses_vs_budget_quarter_pct' => round($this->percentOf($spent, $budgetQ), 2),
                'budget_annual' => $budgetY,
                'expenses_vs_budget_annual_pct' => round($this->percentOf($spent, $budgetY), 2),
            ];

            $totalExpensesQuarter += $spent;
            $totalBudgetQuarter += $budgetQ;
            $totalBudgetAnnual += $budgetY;
        }

        return [
            'rows' => $rows,
            'total_expenses_quarter' => $totalExpensesQuarter,
            'total_budget_quarter' => $totalBudgetQuarter,
            'total_budget_annual' => $totalBudgetAnnual,
        ];
    }

    /**
     * One row per cost category (amounts rolled up across economic codes).
     */
    private function buildRowsPerCategoryOnly(
        FiscalYear $fiscalYear,
        array $period,
        $categories,
        array $divisionIds,
        array $districtIds,
        array $categoryIds,
        array $economicCodeIds
    ): array {
        $fyId = $fiscalYear->id;
        $mStart = $period['fiscal_month_start'];
        $mEnd = $period['fiscal_month_end'];

        $budgetQuarter = $this->sumBudgetByCategoryOnly($fyId, $mStart, $mEnd, $categoryIds, $economicCodeIds);
        $budgetAnnual = $this->sumBudgetByCategoryOnly($fyId, 1, 12, $categoryIds, $economicCodeIds);
        $expensesQuarter = $this->sumExpensesByCategoryOnly(
            $period['start'],
            $period['end'],
            $divisionIds,
            $districtIds,
            $categoryIds,
            $economicCodeIds
        );

        $rows = [];
        $totalExpensesQuarter = 0.0;
        $totalBudgetQuarter = 0.0;
        $totalBudgetAnnual = 0.0;

        foreach ($categories as $category) {
            $catId = (int) $category->id;
            $spent = (float) ($expensesQuarter[$catId] ?? 0.0);
            $budgetQ = (float) ($budgetQuarter[$catId] ?? 0.0);
            $budgetY = (float) ($budgetAnnual[$catId] ?? 0.0);

            $rows[] = [
                'cost_category_id' => $category->id,
                'cost_category_code' => $category->code,
                'cost_category_name' => $category->name,
                'expenses_quarter' => $spent,
                'budget_quarter' => $budgetQ,
                'expenses_vs_budget_quarter_pct' => round($this->percentOf($spent, $budgetQ), 2),
                'budget_annual' => $budgetY,
                'expenses_vs_budget_annual_pct' => round($this->percentOf($spent, $budgetY), 2),
            ];

            $totalExpensesQuarter += $spent;
            $totalBudgetQuarter += $budgetQ;
            $totalBudgetAnnual += $budgetY;
        }

        return [
            'rows' => $rows,
            'total_expenses_quarter' => $totalExpensesQuarter,
            'total_budget_quarter' => $totalBudgetQuarter,
            'total_budget_annual' => $totalBudgetAnnual,
        ];
    }

    /**
     * spent / budget * 100, or 0 if budget is zero.
     */
    private function percentOf(float $spent, float $budget): float
    {
        return $budget > 0 ? ($spent / $budget) * 100 : 0.0;
    }

    /**
     * Monthly budgets summed by category and economic code for a fiscal month range.
     *
     * @return array<int, array<int, float>> [category_id][economic_code_id] => amount
     */
    private function sumBudgetByCategoryAndEconomicCode(
        int $fiscalYearId,
        int $fiscalMonthStart,
        int $fiscalMonthEnd,
        array $categoryIds,
        array $economicCodeIds
    ): array {
        $query = MonthlyBudget::query()
            ->select([
                'cost_category_id',
                'economic_code_id',
                DB::raw('SUM(amount) as total'),
            ])
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereBetween('fiscal_month', [$fiscalMonthStart, $fiscalMonthEnd])
            ->groupBy('cost_category_id', 'economic_code_id');

        if (!empty($categoryIds)) {
            $query->whereIn('cost_category_id', $categoryIds);
        }
        if (!empty($economicCodeIds)) {
            $query->whereIn('economic_code_id', $economicCodeIds);
        }

        $map = [];
        foreach ($query->get() as $row) {
            $map[(int) $row->cost_category_id][(int) $row->economic_code_id] = (float) $row->total;
        }

        return $map;
    }

    /**
     * Monthly budgets summed by category only (for a fiscal month range).
     *
     * @return array<int, float> category_id => amount
     */
    private function sumBudgetByCategoryOnly(
        int $fiscalYearId,
        int $fiscalMonthStart,
        int $fiscalMonthEnd,
        array $categoryIds,
        array $economicCodeIds
    ): array {
        $query = MonthlyBudget::query()
            ->select([
                'cost_category_id',
                DB::raw('SUM(amount) as total'),
            ])
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereBetween('fiscal_month', [$fiscalMonthStart, $fiscalMonthEnd])
            ->groupBy('cost_category_id');

        if (!empty($categoryIds)) {
            $query->whereIn('cost_category_id', $categoryIds);
        }
        if (!empty($economicCodeIds)) {
            $query->whereIn('economic_code_id', $economicCodeIds);
        }

        $map = [];
        foreach ($query->get() as $row) {
            $map[(int) $row->cost_category_id] = (float) $row->total;
        }

        return $map;
    }

    /**
     * Voucher expenses summed by category and economic code for a calendar date range.
     *
     * @return array<int, array<int, float>>
     */
    private function sumExpensesByCategoryAndEconomicCode(
        Carbon $start,
        Carbon $end,
        array $divisionIds,
        array $districtIds,
        array $categoryIds,
        array $economicCodeIds
    ): array {
        $query = VoucherEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->select([
                'voucher_entries.cost_category_id',
                'voucher_entries.economic_code_id',
                DB::raw('SUM(voucher_entries.amount) as total'),
            ])
            ->whereBetween('vouchers.voucher_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('voucher_entries.cost_category_id', 'voucher_entries.economic_code_id');

        $this->applyVoucherAndEntryFilters($query, $divisionIds, $districtIds, $categoryIds, $economicCodeIds);

        $map = [];
        foreach ($query->get() as $row) {
            $map[(int) $row->cost_category_id][(int) $row->economic_code_id] = (float) $row->total;
        }

        return $map;
    }

    /**
     * Voucher expenses summed by category only for a calendar date range.
     *
     * @return array<int, float>
     */
    private function sumExpensesByCategoryOnly(
        Carbon $start,
        Carbon $end,
        array $divisionIds,
        array $districtIds,
        array $categoryIds,
        array $economicCodeIds
    ): array {
        $query = VoucherEntry::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_entries.voucher_id')
            ->select([
                'voucher_entries.cost_category_id',
                DB::raw('SUM(voucher_entries.amount) as total'),
            ])
            ->whereBetween('vouchers.voucher_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('voucher_entries.cost_category_id');

        $this->applyVoucherAndEntryFilters($query, $divisionIds, $districtIds, $categoryIds, $economicCodeIds);

        $map = [];
        foreach ($query->get() as $row) {
            $map[(int) $row->cost_category_id] = (float) $row->total;
        }

        return $map;
    }

    /**
     * Optional filters: division, district, category, economic code (only applied when IDs are present).
     */
    /**
     * @param \Illuminate\Database\Eloquent\Builder<\App\Models\VoucherEntry> $query
     */
    private function applyVoucherAndEntryFilters($query, array $divisionIds, array $districtIds, array $categoryIds, array $economicCodeIds): void
    {
        if (!empty($divisionIds)) {
            $query->whereIn('vouchers.division_id', $divisionIds);
        }
        if (!empty($districtIds)) {
            $query->whereIn('vouchers.district_id', $districtIds);
        }
        if (!empty($categoryIds)) {
            $query->whereIn('voucher_entries.cost_category_id', $categoryIds);
        }
        if (!empty($economicCodeIds)) {
            $query->whereIn('voucher_entries.economic_code_id', $economicCodeIds);
        }
    }

    public function export(array $report, string $format)
    {
        if ($format === 'excel') {
            return $this->exportExcel($report);
        }
        if ($format === 'pdf') {
            return $this->exportPdf($report);
        }

        throw new \InvalidArgumentException("Unsupported export format: {$format}");
    }

    private function exportExcel(array $report)
    {
        $meta = $report['meta'];
        $rows = $report['rows'];
        $totals = $report['totals'];
        $includeEconomicCode = (int) ($meta['include_economic_code'] ?? 0) === 1;

        $endDateLabel = $meta['quarter_end'];

        if ($includeEconomicCode) {
            $headers = [
                'Cost Category',
                'Economic Code',
                "Expenses as of {$endDateLabel} (currency)",
                "Budget as of {$endDateLabel} (currency)",
                "Budget expenses as of {$endDateLabel} (%)",
                'Total Project Budget (annual, currency)',
                "Project Implementation as of {$endDateLabel} (%)",
            ];
        } else {
            $headers = [
                'Cost Category',
                "Expenses as of {$endDateLabel} (currency)",
                "Budget as of {$endDateLabel} (currency)",
                "Budget expenses as of {$endDateLabel} (%)",
                'Total Project Budget (annual, currency)',
                "Project Implementation as of {$endDateLabel} (%)",
            ];
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Project Financial Report');

        $sheet->fromArray($headers, null, 'A1');

        for ($col = 1; $col <= count($headers); $col++) {
            $coord = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->getStyle($coord)->getFont()->setBold(true);
        }

        $rowNum = 2;
        foreach ($rows as $r) {
            if ($includeEconomicCode) {
                $sheet->setCellValue("A{$rowNum}", $r['cost_category_name']);
                $sheet->setCellValue("B{$rowNum}", $r['economic_code_code']);
                $sheet->setCellValue("C{$rowNum}", $r['expenses_quarter']);
                $sheet->setCellValue("D{$rowNum}", $r['budget_quarter']);
                $sheet->setCellValue("E{$rowNum}", $r['expenses_vs_budget_quarter_pct']);
                $sheet->setCellValue("F{$rowNum}", $r['budget_annual']);
                $sheet->setCellValue("G{$rowNum}", $r['expenses_vs_budget_annual_pct']);
            } else {
                $sheet->setCellValue("A{$rowNum}", $r['cost_category_name']);
                $sheet->setCellValue("B{$rowNum}", $r['expenses_quarter']);
                $sheet->setCellValue("C{$rowNum}", $r['budget_quarter']);
                $sheet->setCellValue("D{$rowNum}", $r['expenses_vs_budget_quarter_pct']);
                $sheet->setCellValue("E{$rowNum}", $r['budget_annual']);
                $sheet->setCellValue("F{$rowNum}", $r['expenses_vs_budget_annual_pct']);
            }
            $rowNum++;
        }

        $sheet->setCellValue("A{$rowNum}", 'Total Project Expenses');
        if ($includeEconomicCode) {
            $sheet->setCellValue("B{$rowNum}", '');
            $sheet->setCellValue("C{$rowNum}", $totals['total_expenses_quarter']);
            $sheet->setCellValue("D{$rowNum}", $totals['total_budget_quarter']);
            $sheet->setCellValue("E{$rowNum}", $totals['expenses_vs_budget_quarter_pct']);
            $sheet->setCellValue("F{$rowNum}", $totals['total_budget_annual']);
            $sheet->setCellValue("G{$rowNum}", $totals['expenses_vs_budget_annual_pct']);

            $sheet->getColumnDimension('A')->setWidth(28);
            $sheet->getColumnDimension('B')->setWidth(18);
            foreach (['C', 'D', 'E', 'F', 'G'] as $col) {
                $sheet->getColumnDimension($col)->setWidth(26);
            }
        } else {
            $sheet->setCellValue("B{$rowNum}", $totals['total_expenses_quarter']);
            $sheet->setCellValue("C{$rowNum}", $totals['total_budget_quarter']);
            $sheet->setCellValue("D{$rowNum}", $totals['expenses_vs_budget_quarter_pct']);
            $sheet->setCellValue("E{$rowNum}", $totals['total_budget_annual']);
            $sheet->setCellValue("F{$rowNum}", $totals['expenses_vs_budget_annual_pct']);

            $sheet->getColumnDimension('A')->setWidth(28);
            foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
                $sheet->getColumnDimension($col)->setWidth(26);
            }
        }

        $reportName = sprintf(
            'project-financial-report_%s_q%s.%s',
            $meta['fiscal_year'],
            (string) $meta['quarter'],
            'xlsx'
        );
        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $reportName);

        $dir = storage_path('app/reports');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        $path = $dir . DIRECTORY_SEPARATOR . $safeName;
        (new Xlsx($spreadsheet))->save($path);

        return response()->download($path, $safeName)->deleteFileAfterSend(true);
    }

    private function exportPdf(array $report)
    {
        $meta = $report['meta'];
        $rows = $report['rows'];
        $totals = $report['totals'];
        $includeEconomicCode = (int) ($meta['include_economic_code'] ?? 0) === 1;

        $endDateLabel = $meta['quarter_end'];

        $style = '
            body { font-family: DejaVu Sans, sans-serif; }
            table { width: 100%; border-collapse: collapse; font-size: 11px; }
            th, td { border: 1px solid #333; padding: 6px 8px; }
            th { background: #f2f2f2; }
            .right { text-align: right; }
        ';

        $html = '<html><head><style>' . $style . '</style></head><body>';
        $html .= '<h2 style="margin:0 0 12px 0;">Project Financial Reporting System</h2>';
        $html .= '<p style="margin:0 0 16px 0;">Fiscal Year: <b>' . e($meta['fiscal_year']) . '</b> | Quarter: <b>' . e((string) $meta['quarter']) . '</b> | As of: <b>' . e($endDateLabel) . '</b></p>';

        $html .= '<table>';
        $html .= '<thead><tr>';
        $html .= '<th>Cost Category</th>';

        if ($includeEconomicCode) {
            $html .= '<th>Economic Code</th>';
            $html .= '<th class="right">Expenses as of ' . e($endDateLabel) . '</th>';
            $html .= '<th class="right">Budget as of ' . e($endDateLabel) . '</th>';
            $html .= '<th class="right">Budget expenses as of ' . e($endDateLabel) . ' (%)</th>';
            $html .= '<th class="right">Total Project Budget (annual)</th>';
            $html .= '<th class="right">Project Implementation as of ' . e($endDateLabel) . ' (%)</th>';
        } else {
            $html .= '<th class="right">Expenses as of ' . e($endDateLabel) . '</th>';
            $html .= '<th class="right">Budget as of ' . e($endDateLabel) . '</th>';
            $html .= '<th class="right">Budget expenses as of ' . e($endDateLabel) . ' (%)</th>';
            $html .= '<th class="right">Total Project Budget (annual)</th>';
            $html .= '<th class="right">Project Implementation as of ' . e($endDateLabel) . ' (%)</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $html .= '<tr>';
            $html .= '<td>' . e($r['cost_category_name']) . '</td>';
            if ($includeEconomicCode) {
                $html .= '<td>' . e($r['economic_code_code']) . '</td>';
                $html .= '<td class="right">' . number_format($r['expenses_quarter'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['budget_quarter'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['expenses_vs_budget_quarter_pct'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['budget_annual'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['expenses_vs_budget_annual_pct'], 2) . '</td>';
            } else {
                $html .= '<td class="right">' . number_format($r['expenses_quarter'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['budget_quarter'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['expenses_vs_budget_quarter_pct'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['budget_annual'], 2) . '</td>';
                $html .= '<td class="right">' . number_format($r['expenses_vs_budget_annual_pct'], 2) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '<tr>';
        $html .= '<td><b>Total Project Expenses</b></td>';
        if ($includeEconomicCode) {
            $html .= '<td></td>';
            $html .= '<td class="right"><b>' . number_format($totals['total_expenses_quarter'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['total_budget_quarter'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['expenses_vs_budget_quarter_pct'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['total_budget_annual'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['expenses_vs_budget_annual_pct'], 2) . '</b></td>';
        } else {
            $html .= '<td class="right"><b>' . number_format($totals['total_expenses_quarter'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['total_budget_quarter'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['expenses_vs_budget_quarter_pct'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['total_budget_annual'], 2) . '</b></td>';
            $html .= '<td class="right"><b>' . number_format($totals['expenses_vs_budget_annual_pct'], 2) . '</b></td>';
        }
        $html .= '</tr>';

        $html .= '</tbody></table>';
        $html .= '</body></html>';

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        $fileName = 'project-financial-report_' . $meta['fiscal_year'] . '_q' . $meta['quarter'] . '.pdf';
        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $fileName);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
        ]);
    }
}
