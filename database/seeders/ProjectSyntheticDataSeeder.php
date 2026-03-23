<?php

namespace Database\Seeders;

use App\Models\CostCategory;
use App\Models\District;
use App\Models\EconomicCode;
use App\Models\Division;
use App\Models\FiscalYear;
use App\Models\MonthlyBudget;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherEntry;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProjectSyntheticDataSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create(config('app.faker_locale', 'en_US'));

        // Seed only deterministic “project” domain tables; keep users intact if they exist.
        $users = User::query()->orderBy('id')->get();
        if ($users->isEmpty()) {
            User::factory()->count(10)->create();
            $users = User::query()->orderBy('id')->get();
        }

        // Defaults tuned to generate well over 3000 total records.
        $divisionsCount = (int) (env('SEED_DIVISIONS', 8));
        $districtsPerDivision = (int) (env('SEED_DISTRICTS_PER_DIVISION', 5));
        $fiscalYearsStart = [2024, 2025];
        $fiscalYearsCount = (int) (env('SEED_FISCAL_YEARS', 2));
        $fiscalYearsStart = array_slice($fiscalYearsStart, 0, $fiscalYearsCount);
        // Keep defaults high enough to reliably satisfy the “>= 3000 records” requirement.
        $vouchersPerFiscalYear = (int) (env('SEED_VOUCHERS_PER_FISCAL_YEAR', 260));

        // Categories are aligned with the exercise context/sample.
        $categories = [
            ['code' => 'CAT-1', 'name' => 'Equipment'],
            ['code' => 'CAT-2', 'name' => 'Civil Works'],
            ['code' => 'CAT-3', 'name' => 'Consulting Services'],
            ['code' => 'CAT-4', 'name' => 'PIU Administration Costs'],
            ['code' => 'CAT-5', 'name' => 'Unallocated'],
        ];

        $economicCodesPerCategory = (int) (env('SEED_ECON_CODES_PER_CATEGORY', 6));

        DB::transaction(function () use (
            $faker,
            $users,
            $divisionsCount,
            $districtsPerDivision,
            $fiscalYearsStart,
            $vouchersPerFiscalYear,
            $categories,
            $economicCodesPerCategory
        ) {
            // Clear domain tables first.
            VoucherEntry::query()->delete();
            Voucher::query()->delete();
            MonthlyBudget::query()->delete();
            FiscalYear::query()->delete();
            EconomicCode::query()->delete();
            CostCategory::query()->delete();
            District::query()->delete();
            Division::query()->delete();

            // 1) Regions
            $divisions = collect();
            for ($i = 1; $i <= $divisionsCount; $i++) {
                $divisions->push(
                    Division::create([
                        'code' => sprintf('DIV-%02d', $i),
                        'name' => $faker->unique()->city . ' Division',
                    ])
                );
            }

            $districtsByDivisionId = [];
            foreach ($divisions as $division) {
                $districtsByDivisionId[$division->id] = [];
                for ($j = 1; $j <= $districtsPerDivision; $j++) {
                    $district = District::create([
                        'division_id' => $division->id,
                        'code' => sprintf('DIS-%02d-%02d', $i = $division->id, $j),
                        'name' => $faker->unique()->city . ' District',
                    ]);
                    $districtsByDivisionId[$division->id][] = $district;
                }
            }

            // 2) Cost categories and economic codes
            $costCategories = collect();
            foreach ($categories as $c) {
                $costCategories->push(CostCategory::create($c));
            }

            $economicCodes = collect();
            $economicCodesById = [];
            foreach ($costCategories as $category) {
                for ($k = 1; $k <= $economicCodesPerCategory; $k++) {
                    $code = strtoupper($category->code) . '-' . sprintf('%03d', $k);
                    $econ = EconomicCode::create([
                        'cost_category_id' => $category->id,
                        'code' => $code,
                        'name' => $faker->words(3, true) . ' Services',
                    ]);
                    $economicCodes->push($econ);
                    $economicCodesById[$econ->id] = $econ;
                }
            }

            // 3) Fiscal years
            $fiscalYears = [];
            foreach ($fiscalYearsStart as $startYear) {
                $label = $startYear . '/' . ($startYear + 1);
                $fiscalYears[] = FiscalYear::create([
                    'label' => $label,
                    'start_year' => $startYear,
                    'start_date' => Carbon::create($startYear, 7, 1)->toDateString(),
                    'end_date' => Carbon::create($startYear + 1, 6, 30)->toDateString(),
                ]);
            }

            // 4) Monthly budgets (category+economic code for each fiscal month)
            // We keep an in-memory map so voucher entries can reference budgets quickly.
            $budgetAmountMap = []; // [fiscalYearId][fiscalMonth][economicCodeId] => amount

            foreach ($fiscalYears as $fy) {
                $budgetRows = [];
                for ($fiscalMonth = 1; $fiscalMonth <= 12; $fiscalMonth++) {
                    // Rough seasonality by quarter.
                    $quarter = intdiv($fiscalMonth - 1, 3) + 1;
                    $seasonMultiplier = match ($quarter) {
                        1 => 0.85,
                        2 => 1.05,
                        3 => 1.10,
                        4 => 0.95,
                        default => 1.0,
                    };

                    foreach ($economicCodes as $econ) {
                        // Budget base per economic code: category-driven with variation.
                        $categoryBase = match ($econ->costCategory->code ?? null) {
                            'CAT-1' => 180000,
                            'CAT-2' => 450000,
                            'CAT-3' => 160000,
                            'CAT-4' => 90000,
                            'CAT-5' => 75000,
                            default => 100000,
                        };

                        $amount = round($categoryBase * $seasonMultiplier * $faker->randomFloat(2, 0.6, 1.4), 2);

                        $budgetRows[] = [
                            'fiscal_year_id' => $fy->id,
                            'fiscal_month' => $fiscalMonth,
                            'cost_category_id' => $econ->cost_category_id ?? $econ->costCategory->id,
                            'economic_code_id' => $econ->id,
                            'amount' => $amount,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $budgetAmountMap[$fy->id][$fiscalMonth][$econ->id] = $amount;
                    }
                }

                MonthlyBudget::query()->insert($budgetRows);
            }

            // 5) Vouchers and voucher entries
            // Voucher count is per fiscal year; each voucher has multiple entries.
            $voucherSeqByYear = [];
            foreach ($fiscalYears as $fy) {
                $voucherSeqByYear[$fy->id] = 1;
            }

            $voucherRows = [];
            $voucherEntriesRows = [];

            foreach ($fiscalYears as $fy) {
                $startYear = $fy->start_year;
                $monthsByQuarter = [
                    1 => [7, 8, 9],
                    2 => [10, 11, 12],
                    3 => [1, 2, 3],
                    4 => [4, 5, 6],
                ];

                for ($v = 0; $v < $vouchersPerFiscalYear; $v++) {
                    $quarter = random_int(1, 4);
                    $month = $monthsByQuarter[$quarter][array_rand($monthsByQuarter[$quarter])];

                    // Translate month to the correct calendar year.
                    $calendarYear = $month >= 7 ? $startYear : ($startYear + 1);
                    $day = random_int(1, 28);
                    $voucherDate = Carbon::create($calendarYear, $month, $day);

                    $division = $divisions->random();
                    $district = $districtsByDivisionId[$division->id][array_rand($districtsByDivisionId[$division->id])];

                    $voucherNumber = 'V-' . $fy->start_year . '-' . $voucherSeqByYear[$fy->id] . '-' . strtoupper($faker->bothify('AA??'));
                    $voucherSeqByYear[$fy->id]++;

                    $voucherRows[] = [
                        'voucher_number' => $voucherNumber,
                        'voucher_date' => $voucherDate->toDateString(),
                        'division_id' => $division->id,
                        'district_id' => $district->id,
                        'user_id' => $users->random()->id,
                        'location' => $faker->address . ', ' . $division->name,
                        'notes' => $faker->sentence(8),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Insert vouchers then entries referencing the created voucher IDs.
            // SQLite needs the ID mapping; easiest is to re-fetch the vouchers by voucher_number.
            Voucher::query()->insert($voucherRows);
            $insertedVoucherNumbers = collect($voucherRows)->pluck('voucher_number')->all();
            $insertedVouchers = Voucher::query()
                ->whereIn('voucher_number', $insertedVoucherNumbers)
                ->get()
                ->keyBy('voucher_number');

            $voucherRowsByNumber = collect($voucherRows)->keyBy('voucher_number');
            foreach ($voucherRowsByNumber as $number => $row) {
                /** @var Voucher $voucher */
                $voucher = $insertedVouchers->get($number);

                // Compute fiscal month for voucher_date relative to the voucher fiscal year.
                $voucherDate = Carbon::parse($voucher->voucher_date);
                // Fiscal year starts in July, so fiscal_year.start_year is the year of July.
                $fy = FiscalYear::query()
                    ->where('start_year', $voucherDate->month >= 7 ? $voucherDate->year : $voucherDate->year - 1)
                    ->first();

                if (!$fy) {
                    continue;
                }

                $fiscalMonth = $voucherDate->month >= 7 ? ($voucherDate->month - 6) : ($voucherDate->month + 6);
                $fiscalMonth = max(1, min(12, $fiscalMonth));

                // Ensure enough voucher_entries overall even with random variance.
                $entryCount = random_int(6, 10);
                $chosenEconomicCodes = $economicCodes->random($entryCount);

                foreach ($chosenEconomicCodes as $econ) {
                    $budgetAmount = $budgetAmountMap[$fy->id][$fiscalMonth][$econ->id] ?? 0.0;
                    if ($budgetAmount <= 0) {
                        continue;
                    }

                    // Keep voucher entries as a fraction of the month budget.
                    $fraction = $faker->randomFloat(2, 0.05, 0.40);
                    $amount = round($budgetAmount * $fraction, 2);

                    $voucherEntriesRows[] = [
                        'voucher_id' => $voucher->id,
                        'cost_category_id' => $econ->costCategory->id,
                        'economic_code_id' => $econ->id,
                        'amount' => $amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            VoucherEntry::query()->insert($voucherEntriesRows);
        });
    }
}

