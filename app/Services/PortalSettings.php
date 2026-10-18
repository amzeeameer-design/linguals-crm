<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PortalSettings
{
    public static function read(string $key, array $default = []): array
    {
        if (! Schema::hasTable('portal_settings')) {
            return $default;
        }$value = DB::table('portal_settings')->where('key', $key)->value('value');

        return $value ? json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR) : $default;
    }

    public static function write(string $key, array $value): void
    {
        DB::table('portal_settings')->updateOrInsert(['key' => $key], ['value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), 'updated_at' => now(), 'created_at' => now()]);
    }

    public static function apply(): void
    {
        $s = self::read('integrations');
        if (! empty($s['smtp_host'])) {
            config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => null, 'mail.mailers.smtp.require_tls' => true, 'mail.mailers.smtp.auto_tls' => true, 'mail.mailers.smtp.host' => $s['smtp_host'], 'mail.mailers.smtp.port' => (int) $s['smtp_port'], 'mail.mailers.smtp.scheme' => ($s['smtp_encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp', 'mail.mailers.smtp.username' => $s['smtp_username'] ?? null, 'mail.mailers.smtp.password' => $s['smtp_password'] ?? null, 'mail.from.address' => $s['from_email'], 'mail.from.name' => $s['from_name'] ?? 'The Linguals']);
        }
        foreach (['recaptcha_site_key', 'recaptcha_secret_key'] as $key) {
            if (! empty($s[$key])) {
                config(['talent.'.$key => $s[$key]]);
            }
        }
        config(['portal.location_enabled' => (bool) ($s['location_enabled'] ?? true)]);
    }

    public static function templates(): array
    {
        $defaults = [
            'welcome' => ['subject' => 'Welcome to The Linguals — your next step', 'body' => "Hello {{name}},\n\nThank you for applying to The Linguals. We have received your details.\n\nPlease complete your service-specific information here:\n{{link}}\n\nOur talent team will review your application. Dashboard access follows admin approval.\n\nThe Linguals Talent Team"],
            'onboarding' => ['subject' => 'The Linguals — your onboarding invitation', 'body' => "Hello {{name}},\n\nOur talent team has approved your application. Set up your dashboard using this private link:\n{{link}}\n\nAfter onboarding, complete your profile photo, LinkedIn and ProZ details.\n\nThe Linguals Talent Team"],
            'request_information' => ['subject' => 'The Linguals — more information needed', 'body' => "Hello {{name}},\n\nOur team needs a few additional details:\n{{note}}\n\nUpdate your service details here:\n{{link}}\n\nThe Linguals Talent Team"],
            'rejected' => ['subject' => 'Your application to The Linguals', 'body' => "Hello {{name}},\n\nThank you for your interest in The Linguals. After review, we cannot proceed with onboarding at this time.\n\n{{note}}\n\nThank you for the time you invested.\nThe Linguals Talent Team"],
            'password_reset' => ['subject' => 'Reset your Linguals password', 'body' => "Hello {{name}},\n\nYou requested a password reset. Open this private link to choose a new password:\n{{link}}\n\nThis link expires in 60 minutes. If you did not request this, ignore this email.\n\nThe Linguals Talent Team"],
        ];

        return array_replace($defaults, self::read('email_templates'));
    }

    public static function audit(string $action, array $details = []): void
    {
        DB::table('portal_audit')->insert(['actor_id' => auth()->id(), 'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }
}
