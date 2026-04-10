<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }} — Project Financial Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }
        .table-responsive { max-height: 70vh; }
        .table thead th { position: sticky; top: 0; background: #e9ecef; z-index: 1; }
        .filter-ts .ts-wrapper { width: 100%; }
        .filter-ts .ts-control { min-height: 38px; }
        .filter-ts .ts-dropdown { z-index: 1055; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <h1 class="h4 mb-1">Project Financial Report</h1>
                        <p class="text-muted small mb-0">Preview totals and rows, then export to Excel or PDF.</p>
                        <p id="metaLine" class="text-muted small mt-2 mb-0"></p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a id="exportExcel" href="{{ url('/reports/project-financial/export?format=excel') }}" class="btn btn-dark btn-sm">Export Excel</a>
                        <a id="exportPdf" href="{{ url('/reports/project-financial/export?format=pdf') }}" class="btn btn-outline-secondary btn-sm">Export PDF</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h2 class="h6 mb-1">Filters</h2>
                        <p class="text-muted small mb-0">Pick a <strong>fiscal year</strong> and <strong>quarter</strong> as usual. For division, district, category, and economic code, select <strong>one or more</strong> (tags). Leave empty to include <strong>all</strong>.</p>
                    </div>
                    <span id="statusPill" class="badge text-bg-secondary">Idle</span>
                </div>
                <form id="filtersForm">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label for="fiscal_year_id" class="form-label">Fiscal year</label>
                            <select id="fiscal_year_id" name="fiscal_year_id" class="form-select form-select-sm">
                                <option value="">All (use latest)</option>
                                @foreach ($fiscalYears as $fy)
                                    <option value="{{ $fy->id }}">{{ $fy->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="quarter" class="form-label">Quarter</label>
                            <select id="quarter" name="quarter" class="form-select form-select-sm">
                                <option value="all">All</option>
                                <option value="1">Q1</option>
                                <option value="2">Q2</option>
                                <option value="3">Q3</option>
                                <option value="4">Q4</option>
                            </select>
                        </div>
                        <div class="col-lg-6">
                            <label for="division_ids" class="form-label">Division</label>
                            <div class="filter-ts">
                                <select id="division_ids" name="division_ids[]" class="form-select form-select-sm" multiple>
                                    @foreach ($divisions as $d)
                                        <option value="{{ $d->id }}">{{ $d->name }}@if ($d->code) ({{ $d->code }})@endif</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <label for="district_ids" class="form-label">District</label>
                            <div class="filter-ts">
                                <select id="district_ids" name="district_ids[]" class="form-select form-select-sm" multiple>
                                    @foreach ($divisions as $div)
                                        @php
                                            $groupDistricts = $districts->where('division_id', $div->id);
                                        @endphp
                                        @if ($groupDistricts->isNotEmpty())
                                            <optgroup label="{{ $div->name }}">
                                                @foreach ($groupDistricts as $dist)
                                                    <option value="{{ $dist->id }}">{{ $dist->name }}@if ($dist->code) ({{ $dist->code }})@endif</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <label for="category_ids" class="form-label">Cost category</label>
                            <div class="filter-ts">
                                <select id="category_ids" name="category_ids[]" class="form-select form-select-sm" multiple>
                                    @foreach ($categories as $c)
                                        <option value="{{ $c->id }}">{{ $c->name }}@if ($c->code) ({{ $c->code }})@endif</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <label for="economic_code_ids" class="form-label">Economic code</label>
                            <div class="filter-ts">
                                <select id="economic_code_ids" name="economic_code_ids[]" class="form-select form-select-sm" multiple>
                                    @foreach ($categories as $cat)
                                        @php
                                            $codesInCat = $economicCodes->where('cost_category_id', $cat->id)->sortBy('code');
                                        @endphp
                                        @if ($codesInCat->isNotEmpty())
                                            <optgroup label="{{ $cat->name }}">
                                                @foreach ($codesInCat as $ec)
                                                    <option value="{{ $ec->id }}">{{ $ec->code }}@if ($ec->name) — {{ $ec->name }}@endif</option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    @endforeach
                                    @php
                                        $categoryIdList = $categories->pluck('id')->all();
                                        $orphanCodes = $economicCodes->filter(function ($ec) use ($categoryIdList) {
                                            $cid = $ec->cost_category_id;
                                            if ($cid === null) {
                                                return true;
                                            }

                                            return ! in_array((int) $cid, $categoryIdList, true);
                                        })->sortBy('code');
                                    @endphp
                                    @if ($orphanCodes->isNotEmpty())
                                        <optgroup label="Other">
                                            @foreach ($orphanCodes as $ec)
                                                <option value="{{ $ec->id }}">{{ $ec->code }}@if ($ec->name) — {{ $ec->name }}@endif</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Include economic code</label>
                            <div class="form-check border rounded p-2 bg-light">
                                <input id="include_economic_code" name="include_economic_code" type="checkbox" value="1" class="form-check-input">
                                <label class="form-check-label small" for="include_economic_code">Show category + economic code breakdown</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap align-items-center gap-2 pt-1">
                            <button type="submit" class="btn btn-primary btn-sm">Run report</button>
                            <button id="resetBtn" type="button" class="btn btn-outline-secondary btn-sm">Reset</button>
                            <span id="statusText" class="text-muted small"></span>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-muted small mb-1">Total expenses (quarter)</p>
                        <p id="totalExpenses" class="h5 mb-0">—</p>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-muted small mb-1">Total budget (quarter)</p>
                        <p id="totalBudgetQuarter" class="h5 mb-0">—</p>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-muted small mb-1">Expenses vs budget (quarter)</p>
                        <p id="pctQuarter" class="h5 mb-0">—</p>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-muted small mb-1">Expenses vs budget (annual)</p>
                        <p id="pctAnnual" class="h5 mb-0">—</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body border-bottom py-3">
                <div>
                    <h2 class="h6 mb-1">Rows</h2>
                    <p id="rowsSubtitle" class="text-muted small mb-0">—</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead>
                        <tr id="tableHeadRow"></tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr>
                            <td class="text-muted small">Run the report to see results.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js" crossorigin="anonymous"></script>
    <script>
        const apiUrl = @json(url('/reports/project-financial'));
        const exportUrl = @json(url('/reports/project-financial/export'));

        const el = (id) => document.getElementById(id);

        const tomSelects = {};
        const TOM_MULTI_IDS = ['division_ids', 'district_ids', 'category_ids', 'economic_code_ids'];

        const multiIdsCsv = (id) => {
            const ts = tomSelects[id];
            if (ts) {
                const v = ts.getValue();
                if (Array.isArray(v)) {
                    return v.filter(Boolean).join(',');
                }
                return v ? String(v) : '';
            }
            const s = el(id);
            if (!s || s.tagName !== 'SELECT') {
                return '';
            }
            return Array.from(s.selectedOptions)
                .map((o) => o.value)
                .filter(Boolean)
                .join(',');
        };

        const initTomSelectFilters = () => {
            const placeholders = {
                division_ids: 'Select divisions…',
                district_ids: 'Select districts…',
                category_ids: 'Select categories…',
                economic_code_ids: 'Select economic codes…',
            };
            TOM_MULTI_IDS.forEach((id) => {
                const node = el(id);
                if (!node || typeof TomSelect === 'undefined') {
                    return;
                }
                tomSelects[id] = new TomSelect(node, {
                    plugins: ['remove_button'],
                    create: false,
                    persist: false,
                    maxItems: null,
                    placeholder: placeholders[id] || 'Select…',
                    hideSelected: true,
                    searchField: [],
                });
            });
        };
        const statusText = el('statusText');
        const statusPill = el('statusPill');

        const setStatus = (state, message) => {
            if (statusText) statusText.textContent = message || '';
            if (!statusPill) return;
            statusPill.className = 'badge';
            if (state === 'loading') {
                statusPill.classList.add('text-bg-primary');
                statusPill.textContent = 'Loading';
            } else if (state === 'done') {
                statusPill.classList.add('text-bg-success');
                statusPill.textContent = 'Ready';
            } else if (state === 'error') {
                statusPill.classList.add('text-bg-danger');
                statusPill.textContent = 'Error';
            } else {
                statusPill.classList.add('text-bg-secondary');
                statusPill.textContent = 'Idle';
            }
        };

        const formatCurrency = (n) => {
            const num = Number(n ?? 0);
            if (!Number.isFinite(num)) return '—';
            return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(num);
        };

        const formatPct = (n) => {
            const num = Number(n ?? 0);
            if (!Number.isFinite(num)) return '—';
            return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(num) + '%';
        };

        const dropdownValue = (selectId) => {
            const sel = el(selectId);
            if (!sel) return '';
            return String(sel.value ?? '').trim();
        };

        const getFiltersFromForm = () => {
            const params = new URLSearchParams();
            const fy = dropdownValue('fiscal_year_id');
            const quarter = dropdownValue('quarter') || 'all';
            const division = multiIdsCsv('division_ids');
            const district = multiIdsCsv('district_ids');
            const category = multiIdsCsv('category_ids');
            const economic = multiIdsCsv('economic_code_ids');
            const includeEcon = el('include_economic_code').checked ? '1' : '0';

            if (fy) params.set('fiscal_year_id', fy);
            if (quarter) params.set('quarter', quarter);
            if (division) params.set('division_ids', division);
            if (district) params.set('district_ids', district);
            if (category) params.set('category_ids', category);
            if (economic) params.set('economic_code_ids', economic);
            params.set('include_economic_code', includeEcon);

            return params;
        };

        const setExportLinks = (params) => {
            const excel = new URL(exportUrl, window.location.origin);
            const pdf = new URL(exportUrl, window.location.origin);
            for (const [k, v] of params.entries()) {
                excel.searchParams.set(k, v);
                pdf.searchParams.set(k, v);
            }
            excel.searchParams.set('format', 'excel');
            pdf.searchParams.set('format', 'pdf');
            el('exportExcel').href = excel.toString();
            el('exportPdf').href = pdf.toString();
        };

        const renderTable = (rows, includeEconomicCode) => {
            const head = el('tableHeadRow');
            const body = el('tableBody');
            head.innerHTML = '';
            body.innerHTML = '';

            const headers = includeEconomicCode
                ? ['Cost Category', 'Economic Code', 'Expenses', 'Budget (Quarter)', '% (Quarter)', 'Budget (Annual)', '% (Annual)']
                : ['Cost Category', 'Expenses', 'Budget (Quarter)', '% (Quarter)', 'Budget (Annual)', '% (Annual)'];

            headers.forEach((h) => {
                const th = document.createElement('th');
                th.scope = 'col';
                th.className = 'small text-nowrap';
                th.textContent = h;
                head.appendChild(th);
            });

            const makeTd = (text, right) => {
                const td = document.createElement('td');
                td.className = (right ? 'text-end text-nowrap ' : '') + 'small';
                td.textContent = text;
                return td;
            };

            if (!rows || rows.length === 0) {
                const tr = document.createElement('tr');
                const td = document.createElement('td');
                td.colSpan = headers.length;
                td.className = 'text-muted small py-4';
                td.textContent = 'No rows for the selected filters.';
                tr.appendChild(td);
                body.appendChild(tr);
                return;
            }

            rows.forEach((r) => {
                const tr = document.createElement('tr');
                const cat = `${r.cost_category_code ?? ''} ${r.cost_category_name ?? ''}`.trim();
                tr.appendChild(makeTd(cat || (r.cost_category_name ?? '—')));
                if (includeEconomicCode) {
                    const econ = `${r.economic_code_code ?? ''} ${r.economic_code_name ?? ''}`.trim();
                    tr.appendChild(makeTd(econ || (r.economic_code_code ?? '—')));
                }
                tr.appendChild(makeTd(formatCurrency(r.expenses_quarter), true));
                tr.appendChild(makeTd(formatCurrency(r.budget_quarter), true));
                tr.appendChild(makeTd(formatPct(r.expenses_vs_budget_quarter_pct), true));
                tr.appendChild(makeTd(formatCurrency(r.budget_annual), true));
                tr.appendChild(makeTd(formatPct(r.expenses_vs_budget_annual_pct), true));
                body.appendChild(tr);
            });
        };

        el('resetBtn').addEventListener('click', () => {
            TOM_MULTI_IDS.forEach((id) => {
                if (tomSelects[id]) {
                    tomSelects[id].clear();
                }
            });
            el('filtersForm').reset();
            setStatus('idle', '');
            setExportLinks(getFiltersFromForm());
        });

        el('filtersForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            setStatus('loading', 'Loading...');

            const params = getFiltersFromForm();
            setExportLinks(params);

            const url = new URL(apiUrl, window.location.origin);
            for (const [k, v] of params.entries()) url.searchParams.set(k, v);

            try {
                el('tableBody').innerHTML = '<tr><td colspan="99" class="text-muted small py-4">Loading…</td></tr>';

                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data?.message || 'Failed to load report.');
                }

                const meta = data?.meta ?? {};
                const totals = data?.totals ?? {};
                const rows = data?.rows ?? [];
                const includeEconomicCode = Number(meta.include_economic_code ?? 0) === 1;

                el('metaLine').textContent = 'Fiscal Year: ' + (meta.fiscal_year ?? '—') + ' • Quarter: ' + (meta.quarter ?? '—') + ' • As of: ' + (meta.quarter_end ?? '—');
                el('rowsSubtitle').textContent = rows.length + ' row(s) • ' + (meta.quarter_start ?? '—') + ' → ' + (meta.quarter_end ?? '—');

                el('totalExpenses').textContent = formatCurrency(totals.total_expenses_quarter);
                el('totalBudgetQuarter').textContent = formatCurrency(totals.total_budget_quarter);
                el('pctQuarter').textContent = formatPct(totals.expenses_vs_budget_quarter_pct);
                el('pctAnnual').textContent = formatPct(totals.expenses_vs_budget_annual_pct);

                renderTable(rows, includeEconomicCode);
                setStatus('done', 'Done.');
            } catch (err) {
                setStatus('error', err?.message ? 'Error: ' + err.message : 'Error loading report.');
            }
        });

        initTomSelectFilters();
        setExportLinks(getFiltersFromForm());
        setStatus('idle', '');
    </script>
</body>
</html>
