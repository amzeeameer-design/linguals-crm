<?php

namespace App\Http\Controllers;

use App\Models\TalentApplication;
use App\Services\AgreementPdf;
use App\Services\PortalSettings;
use App\Services\PrivateFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgreementController extends Controller
{
    public function templates(): array
    {
        return PortalSettings::read('nda_templates', config('agreements.templates'));
    }

    public function index(Request $request): View
    {
        $admin = $request->user()->role === 'admin';
        $application = $admin ? null : TalentApplication::where('user_id', $request->user()->id)->firstOrFail();
        $status = $request->query('status', '');
        $search = trim($request->query('q', ''));
        $query = DB::table('agreements')->join('applications', 'applications.id', '=', 'agreements.application_id')->when(! $admin, fn ($query) => $query->where('application_id', $application->id));
        $counts = (clone $query)->select('agreements.status', DB::raw('count(*) as total'))->groupBy('agreements.status')->pluck('total', 'status');
        $agreements = $query->when(in_array($status, ['issued', 'submitted', 'signed']), fn ($query) => $query->where('agreements.status', $status))->when($search !== '', fn ($query) => $query->where('applications.name', 'like', '%'.$search.'%'))->select('agreements.*', 'applications.name')->orderByDesc('agreements.id')->paginate(12)->withQueryString();
        return view('management.agreements', ['admin' => $admin, 'application' => $application, 'agreements' => $agreements, 'counts' => $counts, 'templates' => $this->templates(), 'status' => $status, 'search' => $search, 'applications' => $admin ? TalentApplication::whereNotNull('user_id')->orderBy('name')->get() : collect()]);
    }

    public function editor(string $template): View
    {
        $templates = $this->templates();
        abort_unless(isset($templates[$template]) || $template === 'new', 404);
        return view('management.agreement-template', ['template' => $templates[$template] ?? ['id' => 'new', 'name' => '', 'title' => '', 'version' => '1.0', 'body' => '', 'footer' => config('agreements.footer')]]);
    }

    public function saveTemplate(Request $request, string $template): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'title' => 'required|string|max:180', 'version' => 'required|string|max:80', 'body' => 'required|string|max:50000', 'footer' => 'required|string|max:140']);
        $templates = $this->templates();
        abort_unless(isset($templates[$template]) || $template === 'new', 404);
        $id = $template === 'new' ? (string) Str::uuid() : $template;
        $templates[$id] = ['id' => $id] + $data;
        PortalSettings::write('nda_templates', $templates);
        PortalSettings::audit('nda_template_saved', ['template_id' => $id, 'version' => $data['version']]);
        return redirect(url('admin/agreements/templates/'.$id))->with('notice', 'Template saved. Previously issued agreements keep their original content.');
    }

    private function document(array $template, ?TalentApplication $application = null): array
    {
        $tokens = ['{{contractor_name}}' => $application?->name ?? 'Preview Applicant', '{{contractor_email}}' => $application?->email ?? 'applicant@example.test', '{{reference}}' => $application?->reference ?? 'PREVIEW', '{{effective_date}}' => now()->format('d M Y')];
        return ['title' => strtr($template['title'], $tokens), 'body' => strtr($template['body'], $tokens), 'footer' => $template['footer'], 'version' => $template['version'], 'contractor_name' => $tokens['{{contractor_name}}'], 'company_signatory' => config('agreements.company_signatory'), 'company_title' => config('agreements.company_title')];
    }

    public function preview(string $template): Response
    {
        $templates = $this->templates();
        abort_unless(isset($templates[$template]), 404);
        return $this->pdf(AgreementPdf::render($this->document($templates[$template])));
    }

    private function pdf(string $bytes): Response
    {
        return response($bytes)->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="linguals-agreement.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function storePdf(string $bytes): array
    {
        $path = 'agreements/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $bytes);
        return ['path' => $path, 'name' => 'linguals-agreement.pdf', 'mime' => 'application/pdf', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public function issue(Request $request): RedirectResponse
    {
        if (! $request->filled('template_id')) {
            return app(PortalOperationsController::class)->issue($request);
        }
        $templates = $this->templates();
        $data = $request->validate(['application_id' => 'required|integer|exists:applications,id', 'template_id' => ['required', Rule::in(array_keys($templates))]]);
        DB::transaction(function () use ($data, $templates, $request): void {
            $application = TalentApplication::lockForUpdate()->findOrFail($data['application_id']);
            abort_unless($application->user_id, 422, 'The applicant needs an onboarded account before signing.');
            abort_if(DB::table('agreements')->where('application_id', $application->id)->whereIn('status', ['issued', 'submitted'])->exists(), 409, 'This applicant already has an agreement awaiting signature or review.');
            $snapshot = $this->document($templates[$data['template_id']], $application);
            $file = $this->storePdf(AgreementPdf::render($snapshot));
            $id = DB::table('agreements')->insertGetId(['application_id' => $application->id, 'template_version' => $snapshot['version'], 'status' => 'issued', 'issued_at' => now(), 'signing_evidence' => json_encode(['template' => $file, 'snapshot' => $snapshot, 'template_id' => $data['template_id'], 'issued_by' => $request->user()->id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $application->update(['nda_status' => 'issued']);
            DB::table('portal_messages')->insert(['application_id' => $application->id, 'sender_id' => $request->user()->id, 'sender_role' => 'admin', 'title' => 'Your agreement is ready to sign', 'body' => 'Open Agreements in your dashboard to review and electronically sign '.$snapshot['title'].'.', 'requires_acknowledgement' => false, 'created_at' => now(), 'updated_at' => now()]);
            PortalSettings::audit('agreement_issued', ['agreement_id' => $id, 'document_hash' => $file['sha256']]);
        });
        return back()->with('notice', 'Agreement issued and applicant notified in their portal. No email was sent.');
    }

    public function signing(Request $request, int $id): View
    {
        $agreement = DB::table('agreements')->find($id);
        abort_unless($agreement, 404);
        $application = TalentApplication::findOrFail($agreement->application_id);
        abort_unless($request->user()->role === 'applicant' && $application->user_id === $request->user()->id, 403);
        $evidence = json_decode($agreement->signing_evidence, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(isset($evidence['snapshot']), 409, 'This older agreement requires a signed PDF upload.');
        return view('management.agreement-sign', compact('agreement', 'application', 'evidence'));
    }

    public function sign(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:150', 'title' => 'nullable|string|max:150', 'consent' => 'accepted', 'document_hash' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $data, $id): void {
            $agreement = DB::table('agreements')->lockForUpdate()->find($id);
            abort_unless($agreement, 404);
            $application = TalentApplication::findOrFail($agreement->application_id);
            abort_unless($request->user()->role === 'applicant' && $application->user_id === $request->user()->id, 403);
            abort_unless($agreement->status === 'issued', 409, 'This agreement has already been submitted.');
            $evidence = json_decode($agreement->signing_evidence, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(isset($evidence['snapshot']) && hash_equals($evidence['template']['sha256'], $data['document_hash']) && hash_equals($data['document_hash'], hash('sha256', PrivateFiles::bytes($evidence['template']))), 409, 'The document has changed. Reload and review it before signing.');
            $signature = ['name' => $data['name'], 'title' => $data['title'] ?? '', 'signed_at' => now()->utc()->toIso8601String(), 'email' => $request->user()->email, 'user_id' => $request->user()->id, 'agreement_id' => (string) $id, 'document_hash' => $data['document_hash'], 'ip' => $request->ip(), 'user_agent' => substr($request->userAgent() ?? '', 0, 1000), 'consent' => 'I have read and accept this agreement and intend my typed name to be my electronic signature.'];
            $file = $this->storePdf(AgreementPdf::render($evidence['snapshot'], $signature));
            if (isset($evidence['signature'])) {
                $evidence['previous_signatures'][] = ['signature' => $evidence['signature'], 'signed_file' => $evidence['signed_file']];
            }
            $evidence['signature'] = $signature;
            $evidence['signed_file'] = $file;
            DB::table('agreements')->where('id', $id)->update(['status' => 'submitted', 'signed_path' => $file['path'], 'signing_evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $application->update(['nda_status' => 'submitted']);
            PortalSettings::audit('agreement_electronically_signed', ['agreement_id' => $id, 'document_hash' => $data['document_hash'], 'signed_hash' => $file['sha256']]);
        });
        return redirect(url('portal/agreements'))->with('notice', 'Your electronic signature was recorded. The signed copy is available and awaits admin review.');
    }
}
