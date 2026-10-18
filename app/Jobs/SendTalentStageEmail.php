<?php

namespace App\Jobs;

use App\Models\TalentApplication;
use App\Services\PortalSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SendTalentStageEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public string $deliveryId;

    public function __construct(public int $applicationId, public string $stage, public string $note = '', public bool $manual = false)
    {
        $this->deliveryId = (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        Cache::lock('portal-mail-'.$this->applicationId, 120)->block(10, function () {
            $a = TalentApplication::find($this->applicationId);
            if (! $a) {
                return;
            }
            $p = $a->profile ?? [];
            if (! empty($p['email_deliveries'][$this->deliveryId])) {
                return;
            }
            if ($this->stage === 'welcome' && ($a->status === 'rejected' || (! $this->manual && ! empty($p['emails']['welcome'])))) {
                return;
            }
            if ($this->stage === 'onboarding' && ($a->status !== 'invited' || empty($p['onboarding_approved_at']))) {
                return;
            }
            PortalSettings::apply();
            Mail::purge('smtp');
            $route = $this->stage === 'onboarding' ? 'talent.onboarding' : 'talent.details';
            $link = URL::temporarySignedRoute($route, now()->addDays(14), ['reference' => $a->reference]);
            $template = PortalSettings::templates()[$this->stage] ?? throw new \RuntimeException('Unknown email template');
            $values = ['{{name}}' => $a->name, '{{reference}}' => $a->reference, '{{link}}' => $link, '{{note}}' => $this->note];
            $subject = strtr($template['subject'], $values);
            $body = strtr($template['body'], $values);
            Mail::to($a->email, $a->name)->send(new \App\Mail\TalentStageMail($subject, $body));
            DB::transaction(function () use ($a) {
                $locked = TalentApplication::lockForUpdate()->findOrFail($a->id);
                $p = $locked->profile ?? [];
                $p['emails'][$this->stage] = now()->toIso8601String();
                $p['email_deliveries'][$this->deliveryId] = now()->toIso8601String();
                $locked->update(['profile' => $p]);
            });
        });
    }
}
