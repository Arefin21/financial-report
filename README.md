<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

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
