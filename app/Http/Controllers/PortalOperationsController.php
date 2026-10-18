<?php

namespace App\Http\Controllers;

use App\Models\TalentApplication;
use App\Models\User;
use App\Services\PortalSettings;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalOperationsController extends Controller
{
    public function preferences(Request $request): \Illuminate\Http\JsonResponse
    {
        $application = $this->own($request);
        $data = $request->validate(['updates' => 'required|boolean', 'reminders' => 'required|boolean', 'newsletter' => 'required|boolean']);
        $profile = $application->profile ?? [];
        $profile['notification_preferences'] = $data;
        $application->update(['profile' => $profile]);

        return response()->json(['message' => 'Notification preferences saved.']);
    }

    private function own(Request $r)
    {
        abort_unless($r->user()->role === 'applicant', 403);

        return TalentApplication::where('user_id', $r->user()->id)->firstOrFail();
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current' => 'required|current_password', 'new' => 'required|string|min:12|max:200|different:current', 'confirm' => 'required|same:new']);
        $r->user()->update(['password' => $d['new'], 'remember_token' => Str::random(60)]);
        $r->session()->regenerate();

        return response()->json(['message' => 'Password updated.']);
    }

    public function profile(Request $r)
    {
        $r->validate(['language_entries' => 'sometimes|array|min:1|max:60', 'language_entries.*.language' => 'required|string|max:80|distinct:ignore_case', 'language_entries.*.proficiency' => 'required|in:A1,A2,B1,B2,C1,C2,Native']);
        $a = $this->own($r);
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', Rule::in([$a->email])], 'country' => 'required|string|max:100', 'timezone' => 'required|timezone', 'applicant_type' => 'required|in:individual,company', 'phone' => 'nullable|string|max:80', 'about' => 'nullable|string|max:2000', 'languages' => 'nullable|string|max:2000', 'domains' => 'nullable|string|max:2000', 'company_name' => 'nullable|string|max:200', 'registration_number' => 'nullable|string|max:120', 'website' => 'nullable|url:http,https|max:2048', 'company_size' => 'nullable|integer|min:1|max:100000', 'contact_role' => 'nullable|string|max:120', 'address' => 'nullable|string|max:500', 'whatsapp' => 'nullable|string|max:80', 'nationality' => 'nullable|string|max:100', 'native_language' => 'nullable|string|max:80', 'education' => 'nullable|array|max:30', 'education.*' => 'array|max:3', 'education.*.*' => 'nullable|string|max:1000', 'links' => 'nullable|array|max:30', 'links.*' => 'array|max:2', 'links.*.*' => 'nullable|string|max:2048', 'links.*.1' => 'nullable|url:http,https|max:2048', 'references' => 'nullable|array|max:30', 'references.*' => 'array|max:3', 'references.*.*' => 'nullable|string|max:1000', 'references.*.2' => 'nullable|email|max:254']);
        DB::transaction(function () use ($r, $a, $d) {
            $p = $a->profile ?? [];
            foreach (['phone', 'about', 'company_name', 'registration_number', 'website', 'company_size', 'contact_role', 'address', 'whatsapp', 'nationality', 'native_language', 'education', 'links', 'references'] as $key) {
                $p[$key] = $d[$key] ?? '';
            }$domains = array_values(array_filter(array_map('trim', explode(',', $d['domains'] ?? ''))));
            foreach ($domains as $domain) {
                if (! in_array($domain, config('talent.domains'))) {
                    throw ValidationException::withMessages(['domains' => 'Choose the listed domain names.']);
                }
            }$p['domains'] = $domains;
            $p['declared_languages'] = array_values(array_filter(array_map('trim', explode(',', $d['languages'] ?? ''))));
            $identityChanged = $a->name !== $d['name'] || $a->country !== $d['country'] || $a->applicant_type !== $d['applicant_type'];
            $changes = ['name' => $d['name'], 'phone' => $d['phone'] ?? null, 'country' => $d['country'], 'timezone' => $d['timezone'], 'applicant_type' => $d['applicant_type'], 'profile' => $p];
            if ($identityChanged && $a->kyc_status === 'approved') {
                $changes['kyc_status'] = 'pending';
                $a->events()->create(['actor_id' => $r->user()->id, 'action' => 'identity_details_changed', 'from_status' => $a->status, 'to_status' => $a->status, 'note' => 'Identity-related profile fields changed; verification requires review again.']);
            }
            $a->update($changes);
            $r->user()->update(['name' => $d['name']]);
            if ($r->has('language_entries')) {
                DB::table('application_languages')->where('application_id', $a->id)->delete();
                foreach ($r->input('language_entries') as $language) {
                    DB::table('application_languages')->insert(['application_id' => $a->id, 'language' => $language['language'], 'proficiency' => $language['proficiency']]);
                }
            }
            foreach (['education' => 'education_entries', 'links' => 'professional_links', 'references' => 'references'] as $key => $table) {
                DB::table($table)->where('application_id', $a->id)->delete();
                foreach ($d[$key] ?? [] as $entry) {
                    DB::table($table)->insert(['application_id' => $a->id, 'details' => json_encode($entry), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });

        return response()->json(['message' => 'Profile saved.']);
    }

    public function services(Request $r)
    {
        $a = $this->own($r);
        $d = $r->validate(['services' => 'required|array|min:1|max:18', 'services.*' => ['required', Rule::in(array_keys(config('talent.services')))], 'serviceDetails' => 'nullable|array', 'serviceDetails.*.experience' => 'nullable|integer|min:0|max:80', 'serviceDetails.*.pairs' => 'nullable|array|max:30', 'serviceDetails.*.pairs.*.source' => 'required|string|max:80', 'serviceDetails.*.pairs.*.target' => 'required|string|max:80', 'serviceDetails.*.pairs.*.both' => 'nullable|boolean', 'serviceDetails.*.rates' => 'nullable|array|max:30', 'serviceDetails.*.rates.*.activity' => 'required|string|max:100', 'serviceDetails.*.rates.*.amount' => 'nullable|numeric|min:0|max:99999999', 'serviceDetails.*.rates.*.unit' => ['required', Rule::in(['word', 'page', 'hour', 'minute', 'session', 'half_day', 'full_day', '4_hour_shift', '8_hour_shift', '100_words', 'project', 'half-day', 'day', 'finished-minute'])], 'serviceDetails.*.rates.*.currency' => 'required|in:USD,EUR,GBP,PKR,AED,SAR,CAD,AUD', 'serviceDetails.*.special0' => 'nullable|string|max:2000', 'serviceDetails.*.special1' => 'nullable|string|max:2000', 'serviceDetails.*.special2' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($a, $d) {
            foreach ($d['services'] as $service) {
                $detail = $d['serviceDetails'][$service] ?? [];
                $existing = $a->services()->where('service', $service)->first();
                $s = $a->services()->updateOrCreate(['service' => $service], ['experience_years' => $detail['experience'] ?? null, 'details' => array_replace($existing?->details ?? [], $detail)]);
                DB::table('language_pairs')->where('application_service_id', $s->id)->delete();
                foreach ($detail['pairs'] ?? [] as $pair) {
                    if (strcasecmp(trim($pair['source']), trim($pair['target'])) === 0) {
                        throw ValidationException::withMessages(['serviceDetails' => 'Source and target languages must be different.']);
                    }
                    DB::table('language_pairs')->insert(['application_service_id' => $s->id, 'source_language' => $pair['source'], 'target_language' => $pair['target'], 'both_ways' => (bool) ($pair['both'] ?? false)]);
                }DB::table('service_rates')->where('application_service_id', $s->id)->delete();
                foreach ($detail['rates'] ?? [] as $rate) {
                    DB::table('service_rates')->updateOrInsert(['application_service_id' => $s->id, 'activity' => $rate['activity'], 'unit' => $rate['unit'], 'currency' => $rate['currency']], ['amount' => $rate['amount'] ?? null]);
                }
            }$a->services()->whereNotIn('service', $d['services'])->delete();
            $p = $a->profile ?? [];
            $p['serviceDetails'] = $d['serviceDetails'] ?? [];
            $p['service_update_requires_review'] = true;
            $a->update(['profile' => $p]);
        });

        return response()->json(['message' => 'Services and rates saved.']);
    }

    public function kyc(Request $r, TalentApplication $application)
    {
        $d = $r->validate(['decision' => 'required|in:approved,correction_required,rejected,pending', 'reason' => 'required|string|max:3000']);
        DB::transaction(function () use ($r, $application, $d) {
            $a = TalentApplication::lockForUpdate()->findOrFail($application->id);
            $a->update(['kyc_status' => $d['decision']]);
            $a->events()->create(['actor_id' => $r->user()->id, 'action' => 'kyc_'.$d['decision'], 'from_status' => $a->status, 'to_status' => $a->status, 'note' => $d['reason']]);
        });

        return response()->json(['message' => 'Manual KYC decision saved.']);
    }

    public function edit(TalentApplication $application)
    {
        return view('management.edit', ['application' => $application, 'fields' => config('vendor_fields'), 'languages' => DB::table('application_languages')->where('application_id', $application->id)->get()]);
    }

    public function save(Request $r, TalentApplication $application)
    {
        $rules = ['name' => 'required|string|max:120', 'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($application->user_id)], 'country' => 'nullable|string|max:100', 'timezone' => 'nullable|timezone', 'applicant_type' => 'required|in:individual,company', 'fields' => 'nullable|array', 'languages' => 'nullable|array|max:60', 'languages.*.language' => 'required|string|max:80', 'languages.*.proficiency' => 'required|in:A1,A2,B1,B2,C1,C2,Native'];
        foreach (config('vendor_fields') as $f) {
            $rules['fields.'.$f['key']] = 'nullable|string|max:3000';
        }$d = $r->validate($rules);
        DB::transaction(function () use ($application, $d, $r) {
            $a = TalentApplication::lockForUpdate()->findOrFail($application->id);
            $p = $a->profile ?? [];
            foreach (array_intersect_key($d['fields'] ?? [], array_flip(array_column(config('vendor_fields'), 'key'))) as $key => $value) {
                $p[$key] = $value;
                $p['import_fields'][$key] = $value;
            }$p['import_fields']['full_name'] = $d['name'];
            $p['import_fields']['primary_email'] = $d['email'];
            $p['import_fields']['country'] = $d['country'] ?? null;
            $p['import_fields']['type'] = $d['applicant_type'];
            $a->update(collect($d)->only(['name', 'email', 'country', 'timezone', 'applicant_type'])->all() + ['profile' => $p, 'phone' => $p['phone'] ?? $a->phone]);
            if ($a->user_id) {
                User::findOrFail($a->user_id)->update(['name' => $d['name'], 'email' => $d['email']]);
            }if (array_key_exists('languages', $d)) {
                DB::table('application_languages')->where('application_id', $a->id)->delete();
                foreach ($d['languages'] as $l) {
                    DB::table('application_languages')->insert(['application_id' => $a->id] + $l);
                }
            }$a->events()->create(['actor_id' => $r->user()->id, 'action' => 'profile_updated', 'from_status' => $a->status, 'to_status' => $a->status, 'note' => 'Admin updated submitted profile fields.']);
        });

        return back()->with('notice', 'Profile updated.');
    }

    public function export(Request $r)
    {
        $a = $this->own($r);

        return response()->json($a->load('services'))->header('Content-Disposition', 'attachment; filename="linguals-profile.json"')->header('Cache-Control', 'private, no-store');
    }

    public function messages(Request $request, ?TalentApplication $application = null): \Illuminate\View\View
    {
        $isAdmin = $request->user()->role === 'admin';
        $application = $isAdmin ? $application : $this->own($request);
        $search = trim((string) $request->query('q', ''));
        $recipients = $isAdmin ? TalentApplication::query()->when($search !== '', fn ($query) => $query->where(fn ($filter) => $filter->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))->orderBy('name')->limit(100)->get() : collect();
        $notices = DB::table('portal_messages')->join('applications', 'applications.id', '=', 'portal_messages.application_id')->where('sender_role', 'admin')->when($application, fn ($query) => $query->where('application_id', $application->id))->when($search !== '', fn ($query) => $query->where(fn ($filter) => $filter->where('applications.name', 'like', '%'.$search.'%')->orWhere('portal_messages.title', 'like', '%'.$search.'%')))->select('portal_messages.*', 'applications.name as recipient_name')->orderByDesc('portal_messages.id')->paginate(15)->withQueryString();
        return view('management.notifications', compact('application', 'recipients', 'notices', 'search', 'isAdmin'));
    }

    public function message(Request $request, TalentApplication $application): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['title' => 'nullable|string|max:180', 'body' => 'required|string|max:5000', 'requires_acknowledgement' => 'nullable|boolean']);
        DB::table('portal_messages')->insert(['application_id' => $application->id, 'sender_id' => $request->user()->id, 'sender_role' => 'admin', 'title' => $data['title'] ?? 'Update from The Linguals', 'body' => $data['body'], 'requires_acknowledgement' => $data['requires_acknowledgement'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
        return redirect(url('admin/notifications/'.$application->id))->with('notice', 'Notification sent to the applicant portal. No email was sent.');
    }

    public function sendNotification(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['application_id' => 'required|integer|exists:applications,id']);
        return $this->message($request, TalentApplication::findOrFail($data['application_id']));
    }

    public function notificationStatus(Request $request, int $id): \Illuminate\Http\RedirectResponse
    {
        $application = $this->own($request);
        $notification = DB::table('portal_messages')->where('id', $id)->where('application_id', $application->id)->where('sender_role', 'admin')->first();
        abort_unless($notification, 404);
        $data = $request->validate(['action' => 'required|in:read,acknowledge']);
        abort_if($data['action'] === 'acknowledge' && ! $notification->requires_acknowledgement, 422);
        $changes = ['read_at' => $notification->read_at ?? now(), 'updated_at' => now()];
        if ($data['action'] === 'acknowledge') {
            $changes['acknowledged_at'] = $notification->acknowledged_at ?? now();
        }
        DB::table('portal_messages')->where('id', $id)->update($changes);
        return back()->with('notice', $data['action'] === 'acknowledge' ? 'Notification acknowledged.' : 'Notification marked as read.');
    }

    public function agreements(Request $r)
    {
        $admin = $r->user()->role === 'admin';
        $a = $admin ? null : $this->own($r);

        return view('management.agreements', ['admin' => $admin, 'template' => PortalSettings::read('nda_template'), 'application' => $a, 'agreements' => DB::table('agreements')->join('applications', 'applications.id', '=', 'agreements.application_id')->when(! $admin, fn ($q) => $q->where('application_id', $a->id))->select('agreements.*', 'applications.name')->orderByDesc('agreements.id')->get(), 'applications' => $admin ? TalentApplication::whereNotNull('user_id')->orderBy('name')->get() : collect()]);
    }

    public function ndaTemplate(Request $r)
    {
        $d = $r->validate(['version' => 'required|string|max:80', 'file' => 'required|file|mimes:pdf|max:20480']);
        $meta = PrivateFiles::store($r->file('file'), 'nda-templates');
        PortalSettings::write('nda_template', ['version' => $d['version'], 'file' => $meta]);
        PortalSettings::audit('nda_template_updated', ['version' => $d['version']]);

        return back()->with('notice', 'NDA template uploaded. Existing agreements keep their issued version.');
    }

    public function issue(Request $r)
    {
        $d = $r->validate(['application_id' => 'required|exists:applications,id']);
        $a = TalentApplication::findOrFail($d['application_id']);
        abort_unless($a->user_id, 422, 'Applicant must be onboarded first.');
        $t = PortalSettings::read('nda_template');
        abort_unless(! empty($t['file']), 422, 'Upload your approved NDA template first.');
        DB::transaction(function () use ($a, $t) {
            DB::table('agreements')->insert(['application_id' => $a->id, 'template_version' => $t['version'], 'status' => 'issued', 'issued_at' => now(), 'signing_evidence' => json_encode(['template' => $t['file']]), 'created_at' => now(), 'updated_at' => now()]);
            $a->update(['nda_status' => 'issued']);
        });

        return back()->with('notice', 'NDA issued in the applicant dashboard. No email was sent.');
    }

    public function ndaFile(Request $r, int $id)
    {
        $g = DB::table('agreements')->find($id);
        abort_unless($g, 404);
        $a = TalentApplication::findOrFail($g->application_id);
        abort_unless($r->user()->role === 'admin' || $a->user_id === $r->user()->id, 403);
        $e = json_decode($g->signing_evidence, true);
        $meta = $r->boolean('signed') ? ($e['signed_file'] ?? null) : ($e['template'] ?? null);
        abort_unless($meta, 404);

        return response(PrivateFiles::bytes($meta))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="agreement.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function ndaSubmit(Request $r, int $id)
    {
        $a = $this->own($r);
        $g = DB::table('agreements')->where('application_id', $a->id)->find($id);
        abort_unless($g && $g->status === 'issued', 409);
        abort_if(isset(json_decode($g->signing_evidence, true)['snapshot']), 409, 'Please review and sign this agreement in the portal.');
        $r->validate(['file' => 'required|file|mimes:pdf|max:20480']);
        $meta = PrivateFiles::store($r->file('file'), 'signed-agreements');
        $e = json_decode($g->signing_evidence, true);
        $e['signed_file'] = $meta;
        $e['submitted_at'] = now()->toIso8601String();
        DB::table('agreements')->where('id', $id)->update(['status' => 'submitted', 'signed_path' => $meta['path'], 'signing_evidence' => json_encode($e), 'updated_at' => now()]);

        return back()->with('notice', 'Signed copy submitted for manual review.');
    }

    public function ndaReview(Request $r, int $id)
    {
        $d = $r->validate(['decision' => 'required|in:signed,issued', 'note' => 'required|string|max:3000']);
        $g = DB::table('agreements')->find($id);
        abort_unless($g && $g->status === 'submitted', 409);
        DB::transaction(function () use ($r, $d, $g) {
            $a = TalentApplication::findOrFail($g->application_id);
            DB::table('agreements')->where('id', $g->id)->update(['status' => $d['decision'], 'signed_at' => $d['decision'] === 'signed' ? now() : null, 'updated_at' => now()]);
            $a->update(['nda_status' => $d['decision']]);
            $a->events()->create(['actor_id' => $r->user()->id, 'action' => 'nda_'.$d['decision'], 'from_status' => $a->status, 'to_status' => $a->status, 'note' => $d['note']]);
        });

        return back()->with('notice', 'Agreement review saved.');
    }
}
