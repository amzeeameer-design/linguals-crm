# The Linguals Talent Portal - Milestone 1

Laravel 13 foundation for the agreed talent onboarding system. Applicant form UX is deliberately deferred.

## Implemented
- Admin session login/logout, throttling and role-based access.
- Application database, service records, combined filters and CSV export.
- Acknowledge, request-information and rejection with transaction-safe audit history and stale-review protection.
- Schema for language pairs, rates, education, links, references, tools, document versions, AI assessment coverage, agreements.
- Responsive admin screens and optional local sample data.

## Not yet implemented
Public form/submissions, invitation/account onboarding, outgoing emails, document uploads/scanning/compression, AI provider, KYC approval UI, NDA signing and Excel export. These are upcoming milestones, not simulated features.

## Windows local setup
Install PHP 8.3+ (8.4 recommended), Composer 2 and MySQL. PHP extensions: pdo_mysql, mbstring, xml/dom, curl, fileinfo, zip, openssl. Node is not required for these first screens, which use plain CSS.

1. Extract this project, open a terminal in its folder.
2. Run `composer install`.
3. Copy `.env.example` to `.env` (`Copy-Item .env.example .env` in PowerShell).
4. Create a MySQL database named `linguals_portal`.
5. Set DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_PORT=3306, DB_DATABASE=linguals_portal, DB_USERNAME and DB_PASSWORD in `.env`. Leave MAIL_MAILER=log until a mail provider is configured.
6. Run `php artisan key:generate`, then `php artisan migrate`.
7. Run `php artisan portal:admin` and enter your own credentials.
8. Optional samples: `php artisan db:seed --class=DemoSeeder` (local only).
9. Run `php artisan serve`; open http://127.0.0.1:8000/login.

## Tests
`php artisan test` uses disposable SQLite memory, not your MySQL data. Includes access control, review transitions, stale decisions, filtering, export scope, CSV formula protection and HTML escaping. MySQL integration must also be checked on your local installation.

## Document assessment contract
Preserve original KYC bytes privately; hash each document version. Assessments reference immutable document records and record provider/model, findings, coverage and failures. AI must never claim authenticity; unavailable checks are distinct from passed checks. Integration still to be implemented. No external provider receives files in this build.
