<?php

namespace App\Http\Controllers;

use App\Jobs\SendTalentStageEmail;
use App\Models\TalentApplication;
use App\Services\PortalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index(Request $r)
    {
        return view('admin.index', ['applications' => TalentApplication::filtered($r->query())->with('services')->latest()->paginate(15)->withQueryString(), 'counts' => ['Applications' => TalentApplication::count(), 'Awaiting review' => TalentApplication::where('status', 'under_review')->count(), 'KYC pending' => TalentApplication::where('kyc_status', 'pending')->count(), 'Active profiles' => TalentApplication::where('status', 'active')->count()]]);
    }

    public function show(TalentApplication $application)
    {
        return redirect(url('workspace').'#/admin/review/'.$application->id);
    }

    public function review(Request $r, TalentApplication $application)
    {
        $d = $r->validate(['action' => 'required|in:acknowledge,request_information,reject,approve_onboarding', 'note' => 'nullable|string|max:3000', 'expected_status' => 'required|string']);
        if (in_array($d['action'], ['request_information', 'reject']) && blank($d['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'A reason is required.']);
        }
        DB::transaction(function () use ($r, $application, $d) {
            $a = TalentApplication::lockForUpdate()->findOrFail($application->id);
            if ($a->status !== $d['expected_status']) {
                throw ValidationException::withMessages(['action' => 'This application changed. Refresh before reviewing.']);
            }
            $allowed = ['acknowledge' => ['submitted'], 'request_information' => ['submitted', 'under_review', 'information_requested'], 'reject' => ['submitted', 'under_review', 'information_requested', 'invited'], 'approve_onboarding' => ['under_review', 'information_requested']];
            if (! in_array($a->status, $allowed[$d['action']])) {
                throw ValidationException::withMessages(['action' => 'This action is not available at this stage.']);
            }
            $p = $a->profile ?? [];
            if ($d['action'] === 'approve_onboarding') {
                if (empty($p['service_stage_completed_at'])) {
                    throw ValidationException::withMessages(['action' => 'Service-specific information must be submitted before onboarding approval.']);
                }
                PortalSettings::apply();
                if (in_array(config('mail.default'), ['log', 'array']) || config('queue.default') !== 'database') {
                    throw ValidationException::withMessages(['action' => 'Configure SMTP and the database queue before sending an onboarding link.']);
                }
                $p['onboarding_approved_at'] = now()->toIso8601String();
                $p['onboarding_approved_by'] = $r->user()->id;
                $p['stage'] = 'awaiting_onboarding';
            }
            $to = ['acknowledge' => 'under_review', 'request_information' => 'information_requested', 'reject' => 'rejected', 'approve_onboarding' => 'invited'][$d['action']];
            $a->events()->create(['actor_id' => $r->user()->id, 'action' => $d['action'], 'from_status' => $a->status, 'to_status' => $to, 'note' => $d['note'] ?? null]);
            $a->update(['status' => $to, 'profile' => $p]);
            if ($d['action'] === 'approve_onboarding') {
                Queue::connection('database')->push(new SendTalentStageEmail($a->id, 'onboarding'));
            }
        });

        return $r->expectsJson() ? response()->json(['message' => 'Review saved.']) : back()->with('notice', 'Review saved.');
    }

    public function export(Request $r)
    {
        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Name', 'Email', 'Type', 'Status', 'KYC', 'NDA', 'Country', 'Timezone', 'Profile']);
            foreach (TalentApplication::filtered($r->query())->orderBy('id')->cursor() as $a) {
                $row = [$a->reference, $a->name, $a->email, $a->applicant_type, $a->status, $a->kyc_status, $a->nda_status, $a->country, $a->timezone, json_encode($a->profile)];
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-\t\r\n]/', (string) $v) ? "'".$v : $v, $row));
            }fclose($out);
        }, 'talent-applications.csv', ['Content-Type' => 'text/csv']);
    }
}
