<?php

namespace App\Http\Controllers;

use App\Jobs\PurgeApplicantFiles;
use App\Jobs\SendTalentStageEmail;
use App\Jobs\AssessDocument;
use App\Models\TalentApplication;
use App\Models\User;
use App\Services\PortalSettings;
use App\Services\PrivateFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicantDataController extends Controller
{
    public function photo(Request $request, TalentApplication $application): RedirectResponse
    {
        $request->validate(['profile_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $path = PrivateFiles::photo($request->file('profile_image'));
        try {
            DB::transaction(function () use ($application, $path): void {
                $locked = TalentApplication::lockForUpdate()->findOrFail($application->id);
                $profile = $locked->profile ?? [];
                $previous = $profile['profile_image_path'] ?? null;
                $profile['profile_image_path'] = $path;
                $locked->update(['profile' => $profile]);
                if ($previous && ! TalentApplication::where('id', '!=', $locked->id)->where('profile->profile_image_path', $previous)->exists()) {
                    Queue::connection('database')->push(new PurgeApplicantFiles([$previous]), '', 'documents');
                }
                PortalSettings::audit('applicant_photo_updated', ['application_id' => $locked->id]);
            });
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            throw $error;
        }

        return back()->with('notice', 'Profile photo updated. The new image is available in the applicant profile.');
    }

    public function confirm(TalentApplication $application): View
    {
        return view('management.delete-applicant', ['application' => $application]);
    }

    public function destroy(Request $request, TalentApplication $application): RedirectResponse
    {
        $request->validate([
            'reference' => ['required', 'string', Rule::in([$application->reference])],
            'password' => ['required', 'current_password'],
            'confirm_deletion' => ['accepted'],
        ], ['reference.in' => 'The applicant reference must match exactly.']);

        Cache::lock('portal-mail-'.$application->id, 120)->block(10, function () use ($application): void {
            DB::transaction(function () use ($application): void {
                $locked = TalentApplication::lockForUpdate()->findOrFail($application->id);
                $user = $locked->user_id ? User::lockForUpdate()->find($locked->user_id) : null;
                abort_if($user && $user->role !== 'applicant', 409, 'An administrator account cannot be deleted here.');
                $documents = DB::table('documents')->where('application_id', $locked->id)->get();
                $documentIds = $documents->pluck('id')->all();
                $paths = $documents->pluck('path')->all();
                $paths[] = $locked->profile['profile_image_path'] ?? null;
                $paths[] = $locked->profile['cv_attachment']['path'] ?? null;
                foreach (DB::table('agreements')->where('application_id', $locked->id)->get() as $agreement) {
                    $paths[] = $agreement->signed_path;
                    $evidence = json_decode($agreement->signing_evidence ?? '{}', true) ?? [];
                    $paths[] = $evidence['signed_file']['path'] ?? null;
                    // Generated applicant PDFs are separate from shared uploaded NDA templates.
                    if (isset($evidence['snapshot'])) {
                        $paths[] = $evidence['template']['path'] ?? null;
                    }
                }
                $paths = array_values(array_unique(array_filter($paths, fn ($path) => is_string($path) && $path !== '')));
                $paths = array_values(array_filter($paths, function (string $path) use ($locked): bool {
                    return ! DB::table('documents')->where('application_id', '!=', $locked->id)->where('path', $path)->exists()
                        && ! TalentApplication::where('id', '!=', $locked->id)->where('profile->profile_image_path', $path)->exists();
                }));
                foreach (['jobs', 'failed_jobs'] as $table) {
                    DB::table($table)->select('id', 'payload')->orderBy('id')->chunkById(100, function ($jobs) use ($table, $locked, $documentIds): void {
                        foreach ($jobs as $job) {
                            $payload = json_decode($job->payload, true) ?? [];
                            $command = $payload['data']['command'] ?? '';
                            $isMail = ($payload['displayName'] ?? '') === SendTalentStageEmail::class && str_contains($command, 's:13:"applicationId";i:'.$locked->id.';');
                            $isDocument = ($payload['displayName'] ?? '') === AssessDocument::class && collect($documentIds)->contains(fn ($id) => str_contains($command, 's:10:"documentId";i:'.$id.';'));
                            if ($isMail || $isDocument) {
                                DB::table($table)->where('id', $job->id)->delete();
                            }
                        }
                    });
                }
                $referenceHash = hash('sha256', $locked->reference);
                $locked->delete();
                if ($user) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                    DB::table('portal_messages')->where('sender_id', $user->id)->delete();
                    DB::table('review_events')->where('actor_id', $user->id)->delete();
                    DB::table('portal_audit')->where('actor_id', $user->id)->delete();
                    $user->delete();
                }
                Queue::connection('database')->push(new PurgeApplicantFiles($paths), '', 'documents');
                PortalSettings::audit('applicant_deleted', ['reference_hash' => $referenceHash, 'private_file_cleanup' => 'queued']);
            });
        });

        return redirect(url('workspace').'#/admin/talent')->with('notice', 'Applicant data and account removed. Private file cleanup is queued and will run with the document worker.');
    }
}
