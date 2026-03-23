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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProjectFinancialReportService
{
    public function generate(array $filters): array
    {
        $quarter = strtolower(trim((string) ($filters['quarter'] ?? 'all')));
        $fiscalYearId = (int) ($filters['fiscal_year_id'] ?? 0);
        $includeEconomicCode = ((int) ($filters['include_economic_code'] ?? 0)) === 1;

        $divisionIds = array_values(array_filter($filters['division_ids'] ?? []));
        $districtIds = array_values(array_filter($filters['district_ids'] ?? []));
        $categoryIds = array_values(array_filter($filters['category_ids'] ?? []));
        $economicCodeIds = array_values(array_filter($filters['economic_code_ids'] ?? []));

        if ($fiscalYearId <= 0) {
            // If fiscal_year_id is missing, default to the latest fiscal year for a better UX.
            $latestFiscalYearId = FiscalYear::query()->orderByDesc('id')->value('id');
            if (!$latestFiscalYearId) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => ['fiscal_year_id is required.'],
                ]);
            }
            $fiscalYearId = (int) $latestFiscalYearId;
        }

        $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);
        $fyStart = Carbon::parse($fiscalYear->start_date);
        $fyEnd = Carbon::parse($fiscalYear->end_date);

        if ($quarter === 'all') {
            $quarterStart = $fyStart->copy();
            $quarterEnd = $fyEnd->copy();
            $fiscalMonthStart = 1;
            $fiscalMonthEnd = 12;
        } else {
            if (!in_array($quarter, ['1', '2', '3', '4'], true)) {
                throw new \InvalidArgumentException("quarter must be 1..4 or 'all'. Got: {$quarter}");
            }

            $q = (int) $quarter;
            $quarterStart = $fyStart->copy()->addMonths(($q - 1) * 3);
            $quarterEnd = $quarterStart->copy()->addMonths(2)->endOfMonth();

            $fiscalMonthStart = ($q - 1) * 3 + 1;
            $fiscalMonthEnd = $q * 3;
        }

        // Load cost categories used to display names.
        $categoriesQuery = CostCategory::query();
        if (!empty($categoryIds)) {
            $categoriesQuery->whereIn('id', $categoryIds);
        }
        $categories = $categoriesQuery
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
        $categoryById = $categories->keyBy('id');

        $rows = [];
        $totalExpensesQuarter = 0.0;
        $totalBudgetQuarter = 0.0;
        $totalBudgetAnnual = 0.0;

        if ($includeEconomicCode) {
            // Load economic codes within the selected category set (or within explicitly selected economic codes).
            $economicCodesQuery = EconomicCode::query()->select(['id', 'cost_category_id', 'code', 'name']);
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

            $budgetQuarterMap = $this->budgetMap(
                $fiscalYear->id,
                $fiscalMonthStart,
                $fiscalMonthEnd,
                $categoryIds,
                $economicCodeIds
            );
            $budgetAnnualMap = $this->budgetMap($fiscalYear->id, 1, 12, $categoryIds, $economicCodeIds);
            $expenseQuarterMap = $this->expenseMap($quarterStart, $quarterEnd, $divisionIds, $districtIds, $categoryIds, $economicCodeIds);

            foreach ($economicCodes as $econ) {
                $catId = (int) $econ->cost_category_id;
                $category = $categoryById->get($catId);
                if (!$category) {
                    continue;
                }

                $expensesQuarter = (float) ($expenseQuarterMap[$catId][$econ->id] ?? 0.0);
                $budgetQuarter = (float) ($budgetQuarterMap[$catId][$econ->id] ?? 0.0);
                $budgetAnnual = (float) ($budgetAnnualMap[$catId][$econ->id] ?? 0.0);

                $pctQuarter = $budgetQuarter > 0 ? ($expensesQuarter / $budgetQuarter) * 100 : 0.0;
                $pctAnnual = $budgetAnnual > 0 ? ($expensesQuarter / $budgetAnnual) * 100 : 0.0;

                $rows[] = [
                    'cost_category_id' => $category->id,
                    'cost_category_code' => $category->code,
                    'cost_category_name' => $category->name,
                    'economic_code_id' => $econ->id,
                    'economic_code_code' => $econ->code,
                    'economic_code_name' => $econ->name,
                    'expenses_quarter' => $expensesQuarter,
                    'budget_quarter' => $budgetQuarter,
                    'expenses_vs_budget_quarter_pct' => round($pctQuarter, 2),
                    'budget_annual' => $budgetAnnual,
                    'expenses_vs_budget_annual_pct' => round($pctAnnual, 2),
                ];

                $totalExpensesQuarter += $expensesQuarter;
                $totalBudgetQuarter += $budgetQuarter;
                $totalBudgetAnnual += $budgetAnnual;
            }
        } else {
            $budgetQuarterMap = $this->budgetMapByCategory(
                $fiscalYear->id,
                $fiscalMonthStart,
                $fiscalMonthEnd,
                $categoryIds,
                $economicCodeIds
            );
            $budgetAnnualMap = $this->budgetMapByCategory($fiscalYear->id, 1, 12, $categoryIds, $economicCodeIds);
            $expenseQuarterMap = $this->expenseMapByCategory(
                $quarterStart,
                $quarterEnd,
                $divisionIds,
                $districtIds,
                $categoryIds,
                $economicCodeIds
            );

            foreach ($categories as $category) {
                $catId = (int) $category->id;
                $expensesQuarter = (float) ($expenseQuarterMap[$catId] ?? 0.0);
                $budgetQuarter = (float) ($budgetQuarterMap[$catId] ?? 0.0);
                $budgetAnnual = (float) ($budgetAnnualMap[$catId] ?? 0.0);

                $pctQuarter = $budgetQuarter > 0 ? ($expensesQuarter / $budgetQuarter) * 100 : 0.0;
                $pctAnnual = $budgetAnnual > 0 ? ($expensesQuarter / $budgetAnnual) * 100 : 0.0;

                $rows[] = [
                    'cost_category_id' => $category->id,
                    'cost_category_code' => $category->code,
                    'cost_category_name' => $category->name,
                    'expenses_quarter' => $expensesQuarter,
                    'budget_quarter' => $budgetQuarter,
                    'expenses_vs_budget_quarter_pct' => round($pctQuarter, 2),
                    'budget_annual' => $budgetAnnual,
                    'expenses_vs_budget_annual_pct' => round($pctAnnual, 2),
                ];

                $totalExpensesQuarter += $expensesQuarter;
                $totalBudgetQuarter += $budgetQuarter;
                $totalBudgetAnnual += $budgetAnnual;
            }
        }

        $totalPctQuarter = $totalBudgetQuarter > 0 ? ($totalExpensesQuarter / $totalBudgetQuarter) * 100 : 0.0;
        $totalPctAnnual = $totalBudgetAnnual > 0 ? ($totalExpensesQuarter / $totalBudgetAnnual) * 100 : 0.0;

        return [
            'meta' => [
                'fiscal_year' => $fiscalYear->label,
                'quarter' => $quarter,
                'quarter_start' => $quarterStart->toDateString(),
                'quarter_end' => $quarterEnd->toDateString(),
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

    private function budgetMap(
        int $fiscalYearId,
        int $fiscalMonthStart,
        int $fiscalMonthEnd,
        array $categoryIds,
        array $economicCodeIds
    ): array
    {
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

        $rows = $query->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->cost_category_id][(int) $row->economic_code_id] = (float) $row->total;
        }
        return $map;
    }

    private function budgetMapByCategory(
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

        $rows = $query->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->cost_category_id] = (float) $row->total;
        }

        return $map;
    }

    private function expenseMap(
        Carbon $quarterStart,
        Carbon $quarterEnd,
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
            ->whereBetween('vouchers.voucher_date', [$quarterStart->toDateString(), $quarterEnd->toDateString()])
            ->groupBy('voucher_entries.cost_category_id', 'voucher_entries.economic_code_id');

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

        $rows = $query->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->cost_category_id][(int) $row->economic_code_id] = (float) $row->total;
        }
        return $map;
    }

    private function expenseMapByCategory(
        Carbon $quarterStart,
        Carbon $quarterEnd,
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
            ->whereBetween('vouchers.voucher_date', [$quarterStart->toDateString(), $quarterEnd->toDateString()])
            ->groupBy('voucher_entries.cost_category_id');

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

        $rows = $query->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->cost_category_id] = (float) $row->total;
        }

        return $map;
    }

    public function export(array $report, string $format)
    {
        return match ($format) {
            'excel' => $this->exportExcel($report),
            'pdf' => $this->exportPdf($report),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    private function exportExcel(array $report)
    {
        $meta = $report['meta'];
        $rows = $report['rows'];
        $totals = $report['totals'];
        $includeEconomicCode = (int) ($meta['include_economic_code'] ?? 0) === 1;

        $sheetTitle = 'Project Financial Report';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);

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

        $sheet->fromArray($headers, null, 'A1');

        // Basic header style
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

        // Totals row
        $sheet->setCellValue("A{$rowNum}", 'Total Project Expenses');
        if ($includeEconomicCode) {
            $sheet->setCellValue("B{$rowNum}", '');
            $sheet->setCellValue("C{$rowNum}", $totals['total_expenses_quarter']);
            $sheet->setCellValue("D{$rowNum}", $totals['total_budget_quarter']);
            $sheet->setCellValue("E{$rowNum}", $totals['expenses_vs_budget_quarter_pct']);
            $sheet->setCellValue("F{$rowNum}", $totals['total_budget_annual']);
            $sheet->setCellValue("G{$rowNum}", $totals['expenses_vs_budget_annual_pct']);

            // Column widths
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

            // Column widths
            $sheet->getColumnDimension('A')->setWidth(28);
            foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
                $sheet->getColumnDimension($col)->setWidth(26);
            }
        }

        // Save to temp file
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

