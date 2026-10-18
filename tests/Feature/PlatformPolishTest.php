<?php

namespace Tests\Feature;

use App\Jobs\PurgeApplicantFiles;
use App\Jobs\SendTalentStageEmail;
use App\Models\TalentApplication;
use App\Models\User;
use App\Services\PortalSettings;
use App\Services\PrivateFiles;
use App\Services\SmtpDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_and_test_uses_current_settings_without_exposing_secrets(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/settings', [
            'smtp_host' => 'smtp.example.test', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_username' => 'mail@example.test', 'smtp_password' => ' SMTP-secret-with-spaces ',
            'from_email' => 'mail@example.test', 'from_name' => 'Linguals', 'intent' => 'test', 'recipient' => 'test@example.test',
        ])->assertRedirect()->assertSessionHas('notice');
        $this->assertSame(' SMTP-secret-with-spaces ', PortalSettings::read('integrations')['smtp_password']);
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('accepted', PortalSettings::read('smtp_test')['status']);
        $this->get('/admin/settings')->assertOk()->assertDontSee('SMTP-secret-with-spaces')->assertSee('Save & send test', false);
    }

    public function test_smtp_failure_is_actionable_and_does_not_flash_secrets(): void
    {
        Mail::shouldReceive('purge')->andReturnNull();
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('535 Authentication failed with private-server-message'));
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/settings', [
            'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_password' => 'never-flash-this', 'from_email' => 'mail@example.test', 'from_name' => 'Linguals',
            'intent' => 'test', 'recipient' => 'test@example.test',
        ])->assertSessionHasErrors('recipient')->assertSessionMissing('_old_input.smtp_password');
        $this->assertStringContainsString('SMTP login was rejected', PortalSettings::read('smtp_test')['message']);
        $this->assertStringNotContainsString('private-server-message', PortalSettings::read('smtp_test')['message']);
    }

    public function test_smtp_port_and_hostname_validation_catches_common_configuration_errors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/settings', [
            'smtp_host' => 'https://smtp.example.test', 'smtp_port' => 465, 'smtp_encryption' => 'tls',
            'from_email' => 'mail@example.test', 'from_name' => 'Linguals',
        ])->assertSessionHasErrors('smtp_host');
        $this->post('/admin/settings', [
            'smtp_host' => 'smtp.example.test', 'smtp_port' => 465, 'smtp_encryption' => 'tls',
            'from_email' => 'mail@example.test', 'from_name' => 'Linguals',
        ])->assertSessionHasErrors('smtp_encryption');
        $this->assertStringContainsString('certificate', SmtpDiagnostics::message(new \RuntimeException('certificate verify failed')));
        $this->assertStringContainsString('outbound SMTP', SmtpDiagnostics::message(new \RuntimeException('Connection timed out')));
    }

    public function test_verified_deletion_removes_account_records_and_privately_purges_files(): void
    {
        Storage::fake('local');
        Queue::fake();
        $owner = User::factory()->create();
        $application = TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Delete Test', 'email' => $owner->email, 'applicant_type' => 'individual', 'user_id' => $owner->id, 'profile' => ['profile_image_path' => 'profile-photos/test.jpg']]);
        Storage::disk('local')->put('profile-photos/test.jpg', 'photo');
        Storage::disk('local')->put('application-cvs/test.pdf', 'pdf');
        $document = DB::table('documents')->insertGetId(['application_id' => $application->id, 'category' => 'CV', 'original_name' => 'test.pdf', 'path' => 'application-cvs/test.pdf', 'mime' => 'application/pdf', 'bytes' => 3, 'sha256' => hash('sha256', 'pdf'), 'version' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('AdminPassword123!')]);
        $url = '/admin/applications/'.$application->id.'/delete';
        $this->actingAs($owner)->get($url)->assertForbidden();
        $this->post($url, [])->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Delete Test');
        $this->post($url, ['reference' => 'incorrect', 'password' => 'wrong', 'confirm_deletion' => '1'])->assertSessionHasErrors(['reference', 'password']);
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->post($url, ['reference' => $application->reference, 'password' => 'AdminPassword123!', 'confirm_deletion' => '1'])->assertRedirect(url('workspace').'#/admin/talent');
        $this->assertDatabaseMissing('applications', ['id' => $application->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document]);
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->get('/documents/'.$document)->assertNotFound();
        Queue::assertPushed(PurgeApplicantFiles::class, function ($job): bool {$job->handle(); return true;});
        Storage::disk('local')->assertMissing(['profile-photos/test.jpg', 'application-cvs/test.pdf']);
        $this->assertDatabaseHas('portal_audit', ['action' => 'applicant_deleted']);
        (new SendTalentStageEmail($application->id, 'welcome'))->handle();
    }

    public function test_photo_processing_retains_resolution_without_upscaling(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('photo.jpg', 1400, 1000);
        $path = PrivateFiles::photo($file);
        $info = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame(1400, $info[0]);
        $this->assertSame(1000, $info[1]);
        $small = PrivateFiles::photo(UploadedFile::fake()->image('small.jpg', 160, 160));
        $this->assertSame(160, getimagesizefromstring(Storage::disk('local')->get($small))[0]);
    }

    public function test_only_admin_can_replace_an_applicant_photo_and_previous_file_is_queued_for_cleanup(): void
    {
        Storage::fake('local');
        Queue::fake();
        $application = TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Photo Test', 'email' => 'photo@example.test', 'applicant_type' => 'individual', 'profile' => ['profile_image_path' => 'profile-photos/old.jpg']]);
        $url = '/admin/applications/'.$application->id.'/photo';
        $this->actingAs(User::factory()->create())->post($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post($url, ['profile_image' => UploadedFile::fake()->image('new.png', 1200, 1200)])->assertRedirect();
        $path = $application->fresh()->profile['profile_image_path'];
        $this->assertNotSame('profile-photos/old.jpg', $path);
        Storage::disk('local')->assertExists($path);
        $this->get('/profile-photo/'.$application->reference)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Queue::assertPushed(PurgeApplicantFiles::class, fn ($job) => $job->paths === ['profile-photos/old.jpg']);
    }

    public function test_cleanup_job_rejects_unsafe_paths_and_can_be_retried(): void
    {
        Storage::fake('local');
        $this->expectException(\RuntimeException::class);
        (new PurgeApplicantFiles(['../.env']))->handle();
    }

    public function test_deletion_cancels_queued_welcome_mail_and_retains_the_cleanup_job(): void
    {
        $application = TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Queue Test', 'email' => 'queue@example.test', 'applicant_type' => 'individual']);
        Queue::connection('database')->push(new SendTalentStageEmail($application->id, 'welcome'));
        $this->actingAs(User::factory()->create(['role' => 'admin', 'password' => Hash::make('AdminPassword123!')]))->post('/admin/applications/'.$application->id.'/delete', ['reference' => $application->reference, 'password' => 'AdminPassword123!', 'confirm_deletion' => '1'])->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(PurgeApplicantFiles::class, json_decode(DB::table('jobs')->value('payload'), true)['displayName']);
    }
}
