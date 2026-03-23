## Project Financial Reporting System

This project generates a national development project financial reporting dataset (synthetic data) and produces reports summarizing:
- Quarterly expenses vs quarterly budgets (percentage)
- Quarter expenses vs total annual budget (percentage)
- Breakdown by cost category and economic code
- Optional filters by division, district, category, and economic code
- Export to Excel (`xlsx`) and PDF

### Assumptions
- Fiscal year starts on **1 July** and ends on **30 June** (4 quarters, 3 months each).
- Synthetic voucher entries are generated as a fraction of the monthly budget for each selected economic code.

## Requirements
- PHP `^8.2`
- Composer
- MySQL (set `DB_CONNECTION=mysql` in `.env`)
- Extensions commonly required by Laravel: `pdo_mysql`, `mbstring`, `openssl`

## Setup
1. Create the MySQL database:
   - `financial_report`
2. Configure `.env`:
   - `DB_CONNECTION=mysql`
   - `DB_HOST=127.0.0.1`
   - `DB_PORT=3306`
   - `DB_DATABASE=financial_report`
   - `DB_USERNAME=root`
   - `DB_PASSWORD=...`
3. Install dependencies:
   - `composer install`
4. Generate key (if needed):
   - `php artisan key:generate`

## Run the project (local)
1. Run migrations:
   - `php artisan migrate --force`
2. Seed synthetic data:
   - `php artisan db:seed --force`
3. Start the server:
   - `php artisan serve --host=127.0.0.1 --port=8000`

## Generate reports
### JSON preview
GET `/reports/project-financial`

Example:
- `http://127.0.0.1:8000/reports/project-financial?fiscal_year_id=1&quarter=1`

Quarter parameter:
- `quarter=1..4` for specific quarter
- `quarter=all` for all four quarters

Optional filters (comma-separated values):
- `division_ids=1,2`
- `district_ids=5,7`
- `category_ids=1,2`
- `economic_code_ids=10,11`

### Export (Excel / PDF)
GET `/reports/project-financial/export?format=excel|pdf&fiscal_year_id=...&quarter=...`

Examples:
- Excel: `http://127.0.0.1:8000/reports/project-financial/export?format=excel&fiscal_year_id=1&quarter=1`
- PDF: `http://127.0.0.1:8000/reports/project-financial/export?format=pdf&fiscal_year_id=1&quarter=1`

### Report Output Structure
By default, report rows are generated per **Cost Category only** (matching the sample output format). `economic_code_ids` still works as a filter (it affects the totals).

If you want the detailed breakdown per **Cost Category + Economic Code**, pass:
- `include_economic_code=1`

Example:
- `http://127.0.0.1:8000/reports/project-financial?fiscal_year_id=1&quarter=1&include_economic_code=1`

## Improvements (if more time)
- Add a UI to choose filters and export.
- Add currency formatting (and localization) consistently.
- Add automated tests for report correctness.
- Improve performance (pre-aggregation / caching).
