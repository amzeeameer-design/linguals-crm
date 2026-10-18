<?php

namespace App\Http\Controllers;

use App\Jobs\SendTalentStageEmail;
use App\Models\TalentApplication;
use App\Models\User;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TalentIntakeController extends Controller
{
    private function mailReady(): void
    {
        abort_if(in_array(config('mail.default'), ['log', 'array'], true) || config('queue.default') !== 'database', 503, 'Application email delivery is not configured. Please contact the talent team.');
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'cv_attachment' => 'nullable|file|mimes:pdf,doc,docx|max:20480',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'linkedin' => ['nullable', 'url:http,https', 'max:2048', 'regex:~^https?://([a-z0-9-]+\.)*linkedin\.com(/|$)~i'],
            'proz' => ['nullable', 'url:http,https', 'max:2048', 'regex:~^https?://([a-z0-9-]+\.)*proz\.com(/|$)~i'],
            'request_id' => 'required|uuid', 'type' => 'required|in:individual,company',
            'name' => 'required|string|max:120', 'email' => 'required|email|max:254',
            'country' => 'required|string|max:100', 'timezone' => 'required|timezone',
            'services' => 'nullable|array|max:18', 'services.*' => ['string', 'distinct', Rule::in(array_keys(config('talent.services')))],
            'other_service' => 'nullable|string|max:120', 'consent' => 'accepted', 'human_token' => 'required|string|max:2048',
            'languages' => 'required|array|min:1|max:60', 'languages.*.language' => 'required|string|max:80|distinct:ignore_case',
            'languages.*.proficiency' => 'required|in:A1,A2,B1,B2,C1,C2,Native',
            'domains' => 'nullable|array|max:30', 'domains.*' => Rule::in(config('talent.domains')),
            'about' => 'nullable|string|max:1200', 'experience' => 'nullable|integer|min:0|max:80',
        ]);
        if (in_array('Any Other', $d['services'] ?? [], true) && blank($d['other_service'] ?? null)) {
            throw ValidationException::withMessages(['other_service' => 'Please name your other service.']);
        }
        $existing = TalentApplication::where('reference', $d['request_id'])->first();
        if ($existing) {
            abort_unless(strcasecmp($existing->email, $d['email']) === 0, 409);

            return response()->json(['reference' => $existing->reference], 202);
        }
        $this->mailReady();
        abort_unless(config('talent.recaptcha_secret_key'), 503, 'Human verification is not configured.');
        try {
            $verified = Http::asForm()->timeout(8)->post('https://www.google.com/recaptcha/api/siteverify', ['secret' => config('talent.recaptcha_secret_key'), 'response' => $d['human_token'], 'remoteip' => $request->ip()])->throw()->json();
        } catch (\Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['human_token' => 'Verification is unavailable. Please try again.']);
        }
        if (empty($verified['success']) || ($verified['hostname'] ?? '') !== $request->getHost()) {
            throw ValidationException::withMessages(['human_token' => 'Human verification expired or failed. Please try again.']);
        }
        $storedPaths = [];
        try {
        $application = DB::transaction(function () use ($d, $request, &$storedPaths) {
            $application = TalentApplication::firstOrCreate(['reference' => $d['request_id']], [
                'applicant_type' => $d['type'], 'name' => $d['name'], 'email' => strtolower($d['email']),
                'country' => $d['country'], 'timezone' => $d['timezone'], 'submitted_at' => now(),
                'profile' => ['linkedin' => $d['linkedin'] ?? null, 'proz_id' => $d['proz'] ?? null, 'profile_completion_required' => true, 'stage' => 'basic_submitted', 'consent_at' => now()->toIso8601String(), 'other_service' => $d['other_service'] ?? null, 'cv_languages' => $d['languages'] ?? [], 'cv_domains' => $d['domains'] ?? [], 'about' => $d['about'] ?? '', 'domains' => $d['domains'] ?? [], 'experience_years' => $d['experience'] ?? null],
            ]);
            abort_unless(strcasecmp($application->email, $d['email']) === 0, 409);
            if ($application->wasRecentlyCreated) {
                if ($request->hasFile('cv_attachment')) {
                    $profile = $application->profile;
                    $profile['cv_attachment'] = PrivateFiles::store($request->file('cv_attachment'));
                    $file = $profile['cv_attachment'];
                    $storedPaths[] = $file['path'];
                    PrivateFiles::register($application->id, 'CV', $file);
                    $application->update(['profile' => $profile]);
                }
                if ($request->hasFile('profile_image')) {
                    $profile = $application->profile;
                    $profile['profile_image_path'] = PrivateFiles::photo($request->file('profile_image'));
                    $storedPaths[] = $profile['profile_image_path'];
                    $application->update(['profile' => $profile]);
                }
                foreach ($d['languages'] as $language) {
                    DB::table('application_languages')->insert(['application_id' => $application->id, 'language' => $language['language'], 'proficiency' => $language['proficiency']]);
                }
                foreach ($d['services'] ?? [] as $service) {
                    $record = $application->services()->create(['service' => $service, 'experience_years' => $d['experience'] ?? null]);
                    foreach ($d['domains'] ?? [] as $domain) {
                        DB::table('service_domains')->insert(['application_service_id' => $record->id, 'domain' => $domain]);
                    }
                }
                Queue::connection('database')->push(new SendTalentStageEmail($application->id, 'welcome'));
            }

            return $application;
        });

        } catch (\Throwable $error) {
            foreach ($storedPaths as $path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return response()->json(['reference' => $application->reference], 202);
    }

    private function application(string $reference): TalentApplication
    {
        $application = TalentApplication::where('reference', $reference)->firstOrFail();
        abort_if($application->status === 'rejected', 403);

        return $application;
    }

    public function portalDetails(Request $request): \Illuminate\View\View
    {
        abort_unless($request->user()->role === 'applicant', 403);
        $application = TalentApplication::where('user_id', $request->user()->id)->firstOrFail();

        return $this->details($application->reference, true);
    }

    public function portalSaveDetails(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->role === 'applicant', 403);
        $application = TalentApplication::where('user_id', $request->user()->id)->firstOrFail();
        $this->saveDetails($request, $application->reference);

        return redirect(url('portal').'#/user/services')->with('notice', 'Service details saved for team review.');
    }

    public function details(string $reference, bool $dashboardMode = false): \Illuminate\View\View
    {
        $application = $this->application($reference);

        $ids = $application->services->pluck('id');
        $serviceDomains = DB::table('service_domains')->whereIn('application_service_id', $ids)->get()->groupBy('application_service_id')->map(fn ($rows) => $rows->pluck('domain')->all())->all();
        $servicePairs = DB::table('language_pairs')->whereIn('application_service_id', $ids)->get()->groupBy('application_service_id')->map(fn ($rows) => $rows->skip(1)->map(fn ($pair) => ['source' => $pair->source_language, 'target' => $pair->target_language])->values()->all())->all();
        
        $primaryLanguage = DB::table('application_languages')->where('application_id', $application->id)->first();
        $primaryPairs = DB::table('language_pairs')->whereIn('application_service_id', $ids)->get()->groupBy('application_service_id')->map(fn ($rows) => $rows->first())->all();
        $serviceRates = DB::table('service_rates')->whereIn('application_service_id', $ids)->get()->groupBy('application_service_id')->map(fn ($rows) => $rows->first())->all();

        return view('talent.details', ['application' => $application, 'dashboardMode' => $dashboardMode, 'services' => config('talent.services'), 'domains' => config('talent.domains'), 'serviceDomains' => $serviceDomains, 'servicePairs' => $servicePairs, 'serviceRates' => $serviceRates, 'primaryLanguage' => $primaryLanguage, 'primaryPairs' => $primaryPairs]);
    }

    public function saveDetails(Request $request, string $reference): \Illuminate\View\View
    {
        $application = $this->application($reference);
        $request->validate(['services' => 'required|array|min:1|max:18', 'services.*' => ['string', 'distinct', Rule::in(array_keys(config('talent.services')))]]);
        $rules = ['service_samples' => 'nullable|array|max:18', 'service_samples.*' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png,webp|max:5120', 'services' => 'required|array|min:1|max:18', 'services.*' => ['distinct', Rule::in(array_keys(config('talent.services')))], 'consent' => 'accepted'];
        foreach ($request->input('services', []) as $service) {
            if (! is_string($service) || ! array_key_exists($service, config('talent.services'))) {
                continue;
            }
            $key = Str::slug($service);
            foreach (config('talent.services')[$service] as $field => $label) {
                $rules["details.$key.$field"] = in_array($field, ['source', 'target'], true) ? 'required|string|max:80' : 'required|string|max:2000';
            }
            $rules["details.$key.experience"] = 'required|integer|min:0|max:80';
            $rules["details.$key.language"] = 'required|string|max:80';
            $rules["details.$key.proficiency"] = 'required|in:A1,A2,B1,B2,C1,C2,Native';
            $rules["details.$key.rate"] = 'nullable|string|max:100';
            $schema = config('service_forms.'.$service, []);
            foreach ($schema['setup'] ?? [] as $field => $definition) {
                $rules["details.$key.$field"] = isset($definition['options']) ? ['nullable', Rule::in($definition['options'])] : 'nullable|string|max:2000';
            }
            $rules["details.$key.portfolio_url"] = 'nullable|url:http,https|max:2048';
            $rules["details.$key.availability"] = ['nullable', Rule::in(['Available now', 'Part-time', 'By arrangement', 'Currently unavailable'])];
            $rules["details.$key.turnaround"] = 'nullable|string|max:200';
            $rules["details.$key.notes"] = 'nullable|string|max:2000';
            $rules["details.$key.rate_amount"] = 'nullable|numeric|min:0.0001|max:1000000';
            $rules["details.$key.rate_currency"] = ['required_with:details.'.$key.'.rate_amount', 'nullable', Rule::in(['USD', 'EUR', 'GBP', 'PKR', 'AED', 'SAR', 'CAD', 'AUD'])];
            $rules["details.$key.rate_unit"] = ['required_with:details.'.$key.'.rate_amount', 'nullable', Rule::in($schema['rate_units'] ?? ['hour', 'word', 'project'])];
            $rules["pairs.$key"] = 'nullable|array|max:20';
            $rules["pairs.$key.*.source"] = 'required|string|max:80';
            $rules["pairs.$key.*.target"] = 'required|string|max:80';
            $rules["domains.$key"] = 'nullable|array|max:30';
            $rules["domains.$key.*"] = Rule::in(config('talent.domains'));
        }
        $d = $request->validate($rules);
        $sampleFiles = $request->file('service_samples', []);
        if (collect($sampleFiles)->sum(fn ($file) => $file?->getSize() ?? 0) > 40 * 1024 * 1024) {
            throw ValidationException::withMessages(['service_samples' => 'Keep all samples together below 40 MB.']);
        }
        $storedPaths = [];
        try {
        DB::transaction(function () use ($application, $d, $sampleFiles, $request, &$storedPaths) {
            $a = TalentApplication::lockForUpdate()->findOrFail($application->id);
            $owner = $a->user_id && $request->user()?->role === 'applicant' && $request->user()->id === $a->user_id;
            abort_if(($a->user_id && ! $owner) || $a->status === 'rejected', 409, 'This stage is already closed.');
            $profile = $a->profile ?? [];

            foreach ($d['services'] as $service) {
                $key = Str::slug($service);
                $detail = $d['details'][$key];
                if (isset($sampleFiles[$key])) {
                    $sample = PrivateFiles::store($sampleFiles[$key], 'service-samples');
                    $storedPaths[] = $sample['path'];
                    $detail['sample_document_id'] = PrivateFiles::register($a->id, 'Sample', $sample);
                } else {
                    $existing = $a->services()->where('service', $service)->first();
                    if (! empty($existing?->details['sample_document_id'])) {
                        $detail['sample_document_id'] = $existing->details['sample_document_id'];
                    }
                }
                $record = $a->services()->updateOrCreate(['service' => $service], ['experience_years' => $detail['experience'], 'details' => $detail]);
                $pairs = $d['pairs'][$key] ?? [];
                if (! empty($detail['source']) && ! empty($detail['target'])) {
                    array_unshift($pairs, ['source' => $detail['source'], 'target' => $detail['target']]);
                }
                if ($service === 'Translation' || array_key_exists($key, $d['pairs'] ?? [])) {
                    DB::table('language_pairs')->where('application_service_id', $record->id)->delete();
                    foreach (collect($pairs)->unique(fn ($pair) => strtolower(trim($pair['source']).'|'.trim($pair['target']))) as $pair) {
                        if (strcasecmp(trim($pair['source']), trim($pair['target'])) === 0) {
                            throw ValidationException::withMessages(["pairs.$key" => 'Source and target languages must be different.']);
                        }
                        DB::table('language_pairs')->insert(['application_service_id' => $record->id, 'source_language' => trim($pair['source']), 'target_language' => trim($pair['target']), 'both_ways' => false]);
                    }
                }
                if (array_key_exists('rate_amount', $detail)) {
                    DB::table('service_rates')->where('application_service_id', $record->id)->where('activity', $service)->delete();
                    if ($detail['rate_amount'] !== null) {
                        DB::table('service_rates')->insert(['application_service_id' => $record->id, 'activity' => $service, 'amount' => $detail['rate_amount'], 'currency' => $detail['rate_currency'], 'unit' => $detail['rate_unit']]);
                    }
                }
                if ($service === 'Translation' && array_key_exists('cat_tools', $detail)) {
                    DB::table('service_tools')->where('application_id', $a->id)->where('details->service', $service)->delete();
                    foreach (array_filter(array_map('trim', explode(',', $detail['cat_tools'] ?? ''))) as $tool) {
                        DB::table('service_tools')->insert(['application_id' => $a->id, 'details' => json_encode(['service' => $service, 'tool' => $tool]), 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                DB::table('service_domains')->where('application_service_id', $record->id)->delete();
                foreach ($d['domains'][$key] ?? [] as $domain) {
                    DB::table('service_domains')->insert(['application_service_id' => $record->id, 'domain' => $domain]);
                }
            }
            $a->services()->whereNotIn('service', $d['services'])->delete();

            $languageRows = collect($d['services'])->map(fn ($service) => $d['details'][Str::slug($service)])->map(fn ($detail) => ['application_id' => $a->id, 'language' => $detail['language'], 'proficiency' => $detail['proficiency']])->unique(fn ($r) => strtolower($r['language']))->values()->all();
            foreach ($languageRows as $row) {
                DB::table('application_languages')->updateOrInsert(['application_id' => $a->id, 'language' => $row['language']], ['proficiency' => $row['proficiency']]);
            }
            $profile['service_stage_completed_at'] = now()->toIso8601String();
            if ($owner) {
                $profile['service_update_requires_review'] = true;
                $a->events()->create(['actor_id' => $request->user()->id, 'action' => 'service_details_updated', 'from_status' => $a->status, 'to_status' => $a->status, 'note' => 'Applicant updated their service-specific details.']);
                $a->update(['profile' => $profile]);
            } else {
                $profile['stage'] = 'awaiting_admin_review';
                unset($profile['onboarding_approved_at']);
                $a->update(['profile' => $profile, 'status' => 'under_review']);
            }
        });

        } catch (\Throwable $error) {
            foreach ($storedPaths as $path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            }
            throw $error;
        }

        return view('talent.saved');
    }

    public function onboarding(string $reference)
    {
        $application = $this->application($reference);
        abort_unless(! empty($application->profile['onboarding_approved_at']) && $application->status === 'invited', 403);
        if ($application->user_id) {
            return redirect()->route('login');
        }

        return view('talent.onboarding', compact('application'));
    }

    public function register(Request $request, string $reference)
    {
        $d = $request->validate(['password' => 'required|string|min:12|max:200|confirmed', 'consent' => 'accepted']);
        $application = $this->application($reference);
        $user = DB::transaction(function () use ($application, $d) {
            $a = TalentApplication::lockForUpdate()->findOrFail($application->id);
            abort_unless(! empty($a->profile['onboarding_approved_at']) && ! empty($a->profile['service_stage_completed_at']) && $a->status === 'invited', 403);
            abort_if($a->user_id, 409, 'Your account is already set up. Please sign in.');
            if (User::where('email', $a->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'An account already uses this email. Please sign in or contact the talent team.']);
            }
            $user = new User(['name' => $a->name, 'email' => $a->email, 'password' => $d['password']]);
            $user->role = 'applicant';
            $user->email_verified_at = now();
            $user->save();
            $profile = $a->profile ?? [];
            $profile['stage'] = 'onboarded';
            $a->update(['user_id' => $user->id, 'profile' => $profile, 'status' => 'active']);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('portal');
    }
}
