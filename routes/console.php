<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Artisan::command('portal:resend-next-step {reference}', function () {
    $application = \App\Models\TalentApplication::where('reference', $this->argument('reference'))->firstOrFail();
    if ($application->status === 'rejected' || $application->user_id) { $this->error('This application is closed or already onboarded.'); return; }
    if (in_array(config('mail.default'), ['log', 'array'], true)) { $this->error('Configure a real mail transport first.'); return; }
    $stage = !empty($application->profile['onboarding_approved_at']) && $application->status === 'invited' ? 'onboarding' : 'welcome';
    \Illuminate\Support\Facades\DB::transaction(function () use ($application, $stage) {
        $locked = \App\Models\TalentApplication::lockForUpdate()->findOrFail($application->id);
        $profile = $locked->profile ?? [];
        unset($profile['emails'][$stage]);
        $locked->update(['profile' => $profile]);
        \Illuminate\Support\Facades\Queue::connection('database')->push(new \App\Jobs\SendTalentStageEmail($locked->id, $stage));
    });
    $this->info('A fresh private link is queued for delivery.');
});


Artisan::command('portal:work', function (): int {
    \App\Services\PortalSettings::apply();
    return \Illuminate\Support\Facades\Cache::lock('portal-mail-worker', 90)->get(function (): int {
        $exit = \Illuminate\Support\Facades\Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => config('mail.default') === 'smtp' ? 'default,documents' : 'documents', '--stop-when-empty' => true, '--max-time' => 50,
            '--max-jobs' => 30, '--timeout' => 45, '--tries' => 5, '--sleep' => 1,
        ]);
        \App\Services\PortalSettings::write('mail_worker', ['completed_at' => now()->toIso8601String(), 'exit_code' => $exit]);
        return $exit;
    }) ?? 0;
})->purpose('Process queued applicant emails on shared hosting');

\Illuminate\Support\Facades\Schedule::command('portal:work')->everyMinute()->withoutOverlapping(2);
