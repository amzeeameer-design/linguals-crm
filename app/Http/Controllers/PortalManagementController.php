<?php

namespace App\Http\Controllers;

use App\Jobs\SendTalentStageEmail;
use App\Models\TalentApplication;
use App\Services\DocumentText;
use App\Services\DocumentAssessment;
use App\Services\DocumentPreview;
use App\Services\PortalSettings;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

class PortalManagementController extends Controller
{
    public function readiness(): \Illuminate\View\View
    {
        return view('management.readiness', ['checks' => \App\Services\LaunchReadiness::checks()]);
    }

    public function serviceReview(TalentApplication $application): \Illuminate\View\View
    {
        return view('management.service-review', ['application' => $application->load('services')]);
    }

    public function saveServiceReview(Request $request, TalentApplication $application): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['decision' => 'required|in:accepted,correction_required', 'note' => 'required|string|max:2000']);
        DB::transaction(function () use ($request, $application, $data): void {
            $locked = TalentApplication::lockForUpdate()->findOrFail($application->id);
            abort_unless(! empty($locked->profile['service_update_requires_review']), 409, 'No service update is waiting for review.');
            $profile = $locked->profile ?? [];
            $profile['service_update_requires_review'] = $data['decision'] !== 'accepted';
            $profile['service_review'] = ['decision' => $data['decision'], 'note' => $data['note'], 'reviewed_at' => now()->toIso8601String()];
            $locked->update(['profile' => $profile]);
            $locked->events()->create(['actor_id' => $request->user()->id, 'action' => 'service_update_'.$data['decision'], 'from_status' => $locked->status, 'to_status' => $locked->status, 'note' => $data['note']]);
            DB::table('portal_messages')->insert(['application_id' => $locked->id, 'sender_id' => $request->user()->id, 'sender_role' => 'admin', 'title' => $data['decision'] === 'accepted' ? 'Your service update was reviewed' : 'Please update your service details', 'body' => $data['note'], 'requires_acknowledgement' => $data['decision'] !== 'accepted', 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('notice', 'Service review saved and applicant notification created.');
    }

    public function retryMail(Request $r)
    {
        $d = $r->validate(['id' => 'required|string|max:100']);
        $job = DB::table('failed_jobs')->where('uuid', $d['id'])->first();
        abort_unless($job, 404);
        $payload = json_decode($job->payload, true);
        abort_unless(in_array($payload['displayName'] ?? '', [SendTalentStageEmail::class, \App\Jobs\AssessDocument::class], true), 422, 'Only supported applicant email and document assessment jobs can be retried here.');
        Artisan::call('queue:retry', ['id' => [$d['id']]]);
        PortalSettings::audit('email_delivery_retried', ['job' => $d['id']]);

        return back()->with('notice', 'Background job queued for retry.');
    }

    public function settings()
    {
        $s = PortalSettings::read('integrations');
        $masked = $s;
        unset($masked['smtp_password'],$masked['recaptcha_secret_key']);

        return view('management.settings', ['settings' => $masked, 'hasPassword' => ! empty($s['smtp_password']), 'hasCaptchaSecret' => ! empty($s['recaptcha_secret_key']), 'templates' => PortalSettings::templates(), 'audit' => DB::table('portal_audit')->latest()->limit(20)->get(), 'failed' => DB::table('failed_jobs')->count(), 'pending' => DB::table('jobs')->count(), 'failedDeliveries' => DB::table('failed_jobs')->select('uuid', 'payload', 'failed_at')->latest('id')->limit(20)->get()->filter(fn ($j) => in_array(json_decode($j->payload, true)['displayName'] ?? '', [SendTalentStageEmail::class, \App\Jobs\AssessDocument::class], true))]);
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['smtp_host' => 'nullable|string|max:254', 'smtp_port' => 'required|in:587,465,25,2525', 'smtp_encryption' => 'required|in:tls,ssl', 'smtp_username' => 'nullable|string|max:254', 'smtp_password' => 'nullable|string|max:1000', 'from_email' => 'required|email|max:254', 'from_name' => 'required|string|max:120', 'recaptcha_site_key' => 'nullable|string|max:500', 'recaptcha_secret_key' => 'nullable|string|max:500', 'location_enabled' => 'nullable|boolean']);
        $existing = PortalSettings::read('integrations');
        foreach (['smtp_password', 'recaptcha_secret_key'] as $key) {
            if (empty($d[$key])) {
                $d[$key] = $existing[$key] ?? null;
            }
        }$d['location_enabled'] = $r->boolean('location_enabled');
        PortalSettings::write('integrations', $d);
        PortalSettings::audit('settings_updated', ['fields' => array_keys($d)]);

        return back()->with('notice', 'Integration settings saved. Secret values are encrypted.');
    }

    public function saveTemplate(Request $r, string $stage)
    {
        abort_unless(array_key_exists($stage, PortalSettings::templates()), 404);
        $d = $r->validate(['subject' => 'required|string|max:180', 'body' => 'required|string|max:12000']);
        if (in_array($stage, ['welcome', 'onboarding', 'request_information', 'password_reset']) && ! str_contains($d['body'], '{{link}}')) {
            throw ValidationException::withMessages(['body' => 'Keep {{link}} so the applicant can open their next step.']);
        }if (preg_match('/\{\{(?!name\}\}|link\}\}|reference\}\}|note\}\})/', $d['body'].$d['subject'])) {
            throw ValidationException::withMessages(['body' => 'Use only {{name}}, {{link}}, {{reference}} and {{note}}.']);
        }$all = PortalSettings::templates();
        $all[$stage] = $d;
        PortalSettings::write('email_templates', $all);
        PortalSettings::audit('email_template_updated', ['stage' => $stage]);

        return back()->with('active_template', $stage)->with('notice', 'Email template saved.');
    }

    public function previewTemplate(Request $r, string $stage)
    {
        $t = PortalSettings::templates()[$stage] ?? abort(404);
        $values = ['{{name}}' => 'Sample Applicant', '{{reference}}' => 'SAMPLE', '{{note}}' => 'Please clarify your experience.', '{{link}}' => '[Private signed next-step link]'];

        return view('emails.talent-stage', ['subject' => strtr($t['subject'], $values), 'body' => strtr($t['body'], $values)]);
    }

    public function testSmtp(Request $r)
    {
        $d = $r->validate(['recipient' => 'required|email']);
        PortalSettings::apply();
        if (config('mail.default') !== 'smtp') {
            throw ValidationException::withMessages(['recipient' => 'Save SMTP settings first.']);
        }try {
            Mail::purge('smtp');
            Mail::raw('Your Linguals SMTP connection is working.', fn ($m) => $m->to($d['recipient'])->subject('The Linguals SMTP test'));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['recipient' => 'SMTP delivery failed. Check the credentials, sender and hosting mail restrictions.']);
        }PortalSettings::audit('smtp_test_sent', ['recipient' => $d['recipient']]);

        return back()->with('notice', 'SMTP test sent.');
    }

    public function sendEmail(Request $r, TalentApplication $application)
    {
        if (in_array($r->input('stage'),['welcome','request_information']) && ($application->user_id || $application->status==='rejected')) {
            throw ValidationException::withMessages(['stage'=>'This application stage is closed. Use portal messages for an onboarded applicant.']);
        }
        $d = $r->validate(['stage' => 'required|in:welcome,onboarding,request_information,rejected', 'note' => 'nullable|string|max:3000']);
        if ($d['stage'] === 'onboarding' && ($application->status !== 'invited' || empty($application->profile['onboarding_approved_at']))) {
            throw ValidationException::withMessages(['stage' => 'Approve onboarding before sending this link.']);
        }if ($d['stage'] === 'rejected' && $application->status !== 'rejected') {
            throw ValidationException::withMessages(['stage' => 'Reject the application before sending this template.']);
        }if ($d['stage'] === 'request_information' && blank($d['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'Describe the information you need.']);
        }PortalSettings::apply();
        if (config('mail.default') !== 'smtp') {
            throw ValidationException::withMessages(['stage' => 'Configure SMTP first.']);
        }Queue::connection('database')->push(new SendTalentStageEmail($application->id, $d['stage'], $d['note'] ?? '', true));
        PortalSettings::audit('applicant_email_queued', ['application_id' => $application->id, 'stage' => $d['stage']]);

        return back()->with('notice', 'Email queued.');
    }

    private function document(Request $r, int $id): array
    {
        $d = (array) DB::table('documents')->find($id);
        abort_unless($d, 404);
        $a = TalentApplication::findOrFail($d['application_id']);
        abort_unless($r->user()->role === 'admin' || $a->user_id === $r->user()->id, 403);

        return [$d, $a];
    }

    public function preview(Request $r, int $id): \Illuminate\View\View
    {
        [$d,$a] = $this->document($r, $id);
        $text = ['text' => '', 'coverage' => 'Original unavailable or storage integrity check failed'];
        $intact = false;
        try {
            $bytes = PrivateFiles::bytes($d);
            $intact = PrivateFiles::verify($d, $bytes);
            if ($intact) {
                $text = DocumentText::read($bytes, $d['mime'], $d['original_name']);
            }
        } catch (\Throwable $error) {
            report($error);
        }

        return view('management.document', ['document' => $d, 'application' => $a, 'text' => $text, 'intact' => $intact, 'versions' => DB::table('documents')->where('application_id', $a->id)->where('category', $d['category'])->orderByDesc('version')->get(), 'assessments' => DB::table('document_assessments')->where('document_id', $id)->latest('id')->limit(10)->get(), 'assessment' => DB::table('document_assessments')->where('document_id', $id)->latest('id')->first()]);
    }

    public function pdf(Request $request, int $id): \Illuminate\Http\Response
    {
        [$document] = $this->document($request, $id);
        $bytes = PrivateFiles::bytes($document);
        abort_unless(PrivateFiles::verify($document, $bytes), 409, 'Stored document integrity check failed. Contact the talent team.');
        $pdf = DocumentPreview::render($document, $bytes);

        return response($pdf)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="document-preview.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function files(Request $request): \Illuminate\View\View
    {
        $admin = $request->user()->role === 'admin';
        $filters = $request->validate(['q' => 'nullable|string|max:200', 'category' => 'nullable|in:CV,Identity,Qualification,Certificate,Sample,Other', 'status' => 'nullable|in:pending,accepted,rejected', 'application' => 'nullable|integer', 'archived' => 'nullable|boolean']);
        $documents = DB::table('documents')->join('applications', 'applications.id', '=', 'documents.application_id')
            ->when(! $admin, fn ($query) => $query->where('applications.user_id', $request->user()->id))
            ->when(! empty($filters['application']), fn ($query) => $query->where('application_id', $filters['application']))
            ->when(! empty($filters['q']), fn ($query) => $query->where(fn ($search) => $search->where('original_name', 'like', '%'.$filters['q'].'%')->orWhere('applications.name', 'like', '%'.$filters['q'].'%')))
            ->when(! empty($filters['category']), fn ($query) => $query->where('category', $filters['category']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('documents.status', $filters['status']))
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->select('documents.*', 'applications.name as applicant_name')->latest('documents.id')->paginate(20)->withQueryString();
        $applicants = $admin ? TalentApplication::query()->when($request->filled('application'), fn ($query) => $query->whereKey($request->integer('application')))->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))->orderBy('name')->limit(100)->get(['id', 'name']) : collect();

        return view('management.files', compact('documents', 'admin', 'filters', 'applicants'));
    }

    public function archive(Request $request, int $id): \Illuminate\Http\RedirectResponse
    {
        [$document, $application] = $this->document($request, $id);
        $data = $request->validate(['action' => 'required|in:archive,restore', 'note' => 'required|string|max:1000']);
        abort_if($request->user()->role !== 'admin' && $document['status'] === 'accepted', 403, 'Contact the talent team to archive an accepted document.');
        DB::table('documents')->where('id', $id)->update(['archived_at' => $data['action'] === 'archive' ? now() : null, 'updated_at' => now()]);
        $application->events()->create(['actor_id' => $request->user()->id, 'action' => 'document_'.$data['action'], 'from_status' => $application->status, 'to_status' => $application->status, 'note' => $document['original_name'].': '.$data['note']]);
        PortalSettings::audit('document_'.$data['action'], ['document_id' => $id]);

        return back()->with('notice', 'Document '.$data['action'].' saved. The original file is retained.');
    }

    public function file(Request $r, int $id): \Illuminate\Http\Response
    {
        [$d] = $this->document($r, $id);
        $bytes = PrivateFiles::bytes($d);
        abort_unless(PrivateFiles::verify($d, $bytes), 409, 'Stored document integrity check failed.');
        $mime = in_array($d['mime'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']) ? $d['mime'] : 'application/octet-stream';

        return response($bytes)->header('Content-Type', $mime)->header('Content-Disposition', ($r->boolean('download') || $mime === 'application/octet-stream' ? 'attachment' : 'inline').'; filename="'.preg_replace('/[^a-zA-Z0-9._ -]/', '_', $d['original_name']).'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function assess(Request $r, int $id): \Illuminate\Http\RedirectResponse
    {
        [$d,$a] = $this->document($r, $id);
        $assessment = DocumentAssessment::run($d, $a);
        DB::table('document_assessments')->insert(['document_id' => $id, 'status' => $assessment['status'], 'provider' => $assessment['provider'], 'model_version' => $assessment['model_version'], 'outcome' => $assessment['outcome'], 'findings' => json_encode($assessment['findings']), 'coverage' => json_encode($assessment['coverage']), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        PortalSettings::audit('document_consistency_checked', ['document_id' => $id]);

        return back()->with('notice', 'Internal checks completed. A human review is still required.');
    }

    public function reviewDocument(Request $r, int $id): \Illuminate\Http\RedirectResponse
    {
        [$d,$a] = $this->document($r, $id);
        $v = $r->validate(['status' => 'required|in:pending,accepted,rejected', 'note' => 'required|string|max:2000']);
        if ($v['status'] === 'accepted') {
            abort_unless(PrivateFiles::verify($d, PrivateFiles::bytes($d)), 409, 'A file with failed integrity cannot be accepted.');
            abort_if(! empty($d['archived_at']), 409, 'Restore this document before accepting it.');
        }
        DB::table('documents')->where('id', $id)->update(['status' => $v['status'], 'updated_at' => now()]);
        $a->events()->create(['actor_id' => $r->user()->id, 'action' => 'document_'.$v['status'], 'from_status' => $a->status, 'to_status' => $a->status, 'note' => $d['original_name'].': '.$v['note']]);

        return back()->with('notice', 'Document review saved.');
    }

    public function upload(Request $r): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $admin = $r->user()->role === 'admin';
        $v = $r->validate(['application_id' => $admin ? 'required|integer|exists:applications,id' : 'prohibited', 'category' => 'required|string|in:CV,Identity,Qualification,Certificate,Sample,Other', 'document' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:20480']);
        $a = $admin ? TalentApplication::findOrFail($v['application_id']) : TalentApplication::where('user_id', $r->user()->id)->firstOrFail();
        $f = PrivateFiles::store($r->file('document'), 'applicant-documents');
        try {
        DB::transaction(function () use ($a, $v, $f, $r) {
            TalentApplication::lockForUpdate()->findOrFail($a->id);
            PrivateFiles::register($a->id, $v['category'], $f);
            if ($v['category'] === 'CV') {
                $profile = $a->fresh()->profile ?? [];
                $profile['cv_attachment'] = $f;
                $a->update(['profile' => $profile]);
            }
            $a->events()->create(['actor_id' => $r->user()->id, 'action' => 'document_uploaded', 'from_status' => $a->status, 'to_status' => $a->status, 'note' => $v['category'].': '.$f['name']]);
        });
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($f['path']);
            throw $error;
        }

        return $r->expectsJson() ? response()->json(['message' => 'Document uploaded for review.']) : back()->with('notice', 'Document uploaded privately for review.');
    }
}
