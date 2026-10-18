<?php

namespace App\Http\Controllers;

use App\Models\TalentApplication;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkspaceController extends Controller
{
    public function admin(Request $request)
    {
        $documents = DB::table('documents')->select([
            'application_id', 'id', 'original_name', 'category', 'mime', 'bytes', 'version', 'status', 'created_at',
        ])->whereNull('archived_at')->orderByDesc('id')->get()->groupBy('application_id');
        $languages = DB::table('application_languages')->get()->groupBy('application_id');
        $pairs = DB::table('language_pairs')->get()->groupBy('application_service_id');
        $domains = DB::table('service_domains')->get()->groupBy('application_service_id');
        $rates = DB::table('service_rates')->get()->groupBy('application_service_id');
        $background = [];
        foreach (['education_entries', 'professional_links', 'references', 'service_tools'] as $table) {
            $background[$table] = DB::table($table)->get()->groupBy('application_id');
        }
        $applications = TalentApplication::query()
            ->with(['services', 'events.actor'])->latest()->get()->map(function ($application) use ($documents, $languages, $pairs, $domains, $rates, $background) {
                $data = $application->toArray();
                $data['documents'] = $documents->get($application->id, collect())->toArray();
                $data['languages'] = $languages->get($application->id, collect())->toArray();
                foreach ($background as $table => $entries) {
                    $data[$table] = $entries->get($application->id, collect())->map(function ($entry) {
                        return json_decode($entry->details, true) ?? [];
                    })->values()->all();
                }
                foreach ($data['services'] as &$service) {
                    $service['language_pairs'] = $pairs->get($service['id'], collect())->toArray();
                    $service['domains'] = $domains->get($service['id'], collect())->pluck('domain')->all();
                    $service['rates'] = $rates->get($service['id'], collect())->toArray();
                    $service['tools'] = collect($data['service_tools'])->filter(function ($tool) use ($service) {
                        return ($tool['service'] ?? null) === $service['service'];
                    })->flatMap(function ($tool) {
                        return (array) ($tool['tools'] ?? $tool['tool'] ?? $tool['name'] ?? []);
                    })->values()->all();
                }
                unset($service);

                return $data;
            })->values()->all();

        return view('workspace', ['workspace' => [
            'demo' => false,
            'role' => 'admin',
            'name' => $request->user()->name,
            'user_id' => $request->user()->id,
            'csrf' => csrf_token(),
            'applications' => $applications,
            'importSchema' => config('vendor_fields'),
        ]]);
    }

    public function applicant(Request $request)
    {
        abort_unless($request->user()->role === 'applicant', 403);
        $application = TalentApplication::where('user_id', $request->user()->id)
            ->with('services')->first();

        $payload = $application ? $application->toArray() : null;
        if ($application) {
            $payload['languages'] = DB::table('application_languages')->where('application_id', $application->id)->get()->toArray();
            foreach ($payload['services'] as &$service) {
                $service['domains'] = DB::table('service_domains')->where('application_service_id', $service['id'])->pluck('domain')->all();
                $service['language_pairs'] = DB::table('language_pairs')->where('application_service_id', $service['id'])->get()->toArray();
                $service['rates'] = DB::table('service_rates')->where('application_service_id', $service['id'])->get()->toArray();
            }
            unset($service);
        }

        return view('workspace', ['workspace' => [
            'demo' => false,
            'role' => 'user',
            'name' => $request->user()->name,
            'csrf' => csrf_token(),
            'documents' => $application ? DB::table('documents')->where('application_id', $application->id)->whereNull('archived_at')
                ->select(['id', 'original_name', 'category', 'bytes', 'version', 'status'])->orderByDesc('id')->get()->toArray() : [],
            'application' => $payload ?? [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'status' => 'draft',
            ],
        ]]);
    }

    public function profilePhoto(Request $request, string $reference)
    {
        $application = TalentApplication::where('reference', $reference)->firstOrFail();
        abort_unless($request->user()->role === 'admin' || $application->user_id === $request->user()->id, 403);
        $path = $application->profile['profile_image_path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function saveProfessionalProfile(Request $request)
    {
        abort_unless($request->user()->role === 'applicant', 403);
        $application = TalentApplication::where('user_id', $request->user()->id)->firstOrFail();
        $profile = $application->profile ?? [];
        $d = $request->validate([
            'profile_image' => [(empty($profile['profile_image_path']) ? 'required' : 'nullable'), 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'linkedin' => ['required', 'url:http,https', 'max:2048', 'regex:~^https?://([a-z0-9-]+\.)*linkedin\.com(/|$)~i'],
            'proz' => ['required', 'url:http,https', 'max:2048', 'regex:~^https?://([a-z0-9-]+\.)*proz\.com(/|$)~i'],
        ]);
        if ($request->hasFile('profile_image')) {
            $profile['profile_image_path'] = PrivateFiles::photo($request->file('profile_image'));
        }
        $profile['linkedin'] = $d['linkedin'];
        $profile['proz_id'] = $d['proz'];
        $profile['profile_completion_required'] = false;
        $profile['professional_profile_completed_at'] = now()->toIso8601String();
        $application->update(['profile' => $profile]);

        return response()->json(['message' => 'Professional profile completed.']);
    }
}
