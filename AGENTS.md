<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>


## Linguals fast development workflow

- The Laravel application directory is the repository root. Keep code and tests together; do not depend on parent workspace paths.
- Read the affected implementation and sibling conventions first. Make the smallest cohesive change and reuse shared workspace icons and UI primitives.
- Dependencies are already installed locally. Do not reinstall or change dependencies for routine changes. Dependency changes require user authorization.
- For JS, navigation, matching or deployment-path changes run `npm run check:quick` once after the final edit. Do not repeat successful checks without new changes or new evidence.
- For PHP behavior run `npm run check:backend -- --filter=RelevantTest`. For releases, authentication, storage or permissions changes run the relevant backend tests; run `npm run check:full` before a release. Set PHP_BINARY to the available PHP executable when PHP is not on PATH.
- For visual changes check the affected screen at desktop and mobile sizes. Use a short focused interaction check; do not browse every screen for a spacing or copy edit. JavaScript render tests do not replace visual verification.
- Do not send real emails, issue invitations or sign agreements during verification. Use test fixtures and fake mail/storage. Never use real applicant data in tests or commits.
- Keep `.env`, SMTP/API credentials, applicant imports, uploaded files, database dumps and local databases out of Git. Run `npm run check:repo` before publishing commits.
- Use a short-lived feature branch and a reviewable pull request. Preserve existing user changes. Do not deploy automatically merely because checks pass.
- Report changes, checks actually run and any unresolved limitation briefly. Never claim browser/live verification when only automated tests ran.
