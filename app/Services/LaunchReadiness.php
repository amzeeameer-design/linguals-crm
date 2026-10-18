<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LaunchReadiness
{
    /** @return list<array{label: string, ready: bool, detail: string}> */
    public static function checks(): array
    {
        PortalSettings::apply();
        $worker = PortalSettings::read('mail_worker');
        $recentWorker = ! empty($worker['completed_at']) && \Illuminate\Support\Carbon::parse($worker['completed_at'])->greaterThan(now()->subMinutes(5));
        $missing = collect(['gd', 'zip', 'mbstring', 'dom', 'fileinfo', 'openssl', 'pdo', 'zlib'])->reject(fn (string $extension): bool => extension_loaded($extension))->values();
        $root = Storage::disk('local')->path('');
        $url = config('app.url');
        $smtp = config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address'));

        return [
            ['label' => 'PHP runtime', 'ready' => PHP_VERSION_ID >= 80300, 'detail' => 'Running '.PHP_VERSION.'. Laravel 13 requires PHP 8.3 or later; PHP 8.5 is the development runtime.'],
            ['label' => 'Required extensions', 'ready' => $missing->isEmpty(), 'detail' => $missing->isEmpty() ? 'Image compression, Word text extraction, encryption and document parsing available.' : 'Enable: '.$missing->implode(', ')],
            ['label' => 'Production configuration', 'ready' => app()->environment('production') && ! config('app.debug') && filled(config('app.key')), 'detail' => 'Set APP_ENV=production, APP_DEBUG=false and keep a unique APP_KEY.'],
            ['label' => 'Deployment URL', 'ready' => str_starts_with((string) $url, 'https://') && ! str_contains((string) $url, '.example'), 'detail' => 'APP_URL must match your chosen HTTPS domain and optional subfolder. Current: '.$url],
            ['label' => 'Private storage', 'ready' => is_dir($root) && is_writable($root), 'detail' => 'Private originals live outside public/. Back up private storage and the database together.'],
            ['label' => 'Database migrations', 'ready' => Schema::hasColumn('documents', 'archived_at') && Schema::hasTable('jobs'), 'detail' => 'Run php artisan migrate --force after uploading the release.'],
            ['label' => 'SMTP configuration', 'ready' => $smtp, 'detail' => 'Configure email delivery in Settings, then send a test email and confirm receipt.'],
            ['label' => 'SMTP test recorded', 'ready' => DB::table('portal_audit')->where('action', 'smtp_test_sent')->exists(), 'detail' => 'A successful SMTP test is recorded here. Delivery to the recipient still needs confirmation.'],
            ['label' => 'Google reCAPTCHA', 'ready' => filled(config('talent.recaptcha_site_key')) && filled(config('talent.recaptcha_secret_key')), 'detail' => 'Register the chosen hostname in Google reCAPTCHA and save both keys in Settings.'],
            ['label' => 'Mail queue and cron', 'ready' => config('queue.default') === 'database' && $recentWorker, 'detail' => $recentWorker ? 'Mail worker heartbeat received within five minutes.' : 'Add a once-per-minute cron for php artisan schedule:run. No recent worker heartbeat.'],
            ['label' => 'PDF viewer assets', 'ready' => is_file(public_path('vendor/pdfjs/pdf.mjs')) && is_file(public_path('vendor/pdfjs/pdf.worker.mjs')) && is_file(public_path('vendor/pdfjs/standard_fonts/LiberationSans-Regular.ttf')), 'detail' => 'Viewer runs inside the portal; documents are not sent to external preview services.'],
            ['label' => 'Upload capacity', 'ready' => self::size((string) ini_get('upload_max_filesize')) >= 20 * 1024 * 1024 && self::size((string) ini_get('post_max_size')) >= 64 * 1024 * 1024 && ((string) ini_get('memory_limit') === '-1' || self::size((string) ini_get('memory_limit')) >= 256 * 1024 * 1024), 'detail' => 'Set upload_max_filesize=20M or higher, post_max_size=64M or higher, memory_limit=256M or higher.'],
        ];
    }

    private static function size(string $value): int
    {
        $number = (int) $value;

        return $number * match (strtolower(substr(trim($value), -1))) {'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1};
    }
}
