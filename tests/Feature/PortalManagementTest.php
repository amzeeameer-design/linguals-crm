<?php

namespace Tests\Feature;

use App\Jobs\SendTalentStageEmail;
use App\Models\TalentApplication;
use App\Models\User;
use App\Services\PortalSettings;
use App\Services\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalManagementTest extends TestCase
{
    public function test_admin_can_retry_failed_applicant_mail_without_running_delivery(): void
    {
        config(['queue.default' => 'database']);
        $a = $this->application();
        $id = (string) Str::uuid();
        Queue::connection('database')->push(new SendTalentStageEmail($a->id, 'welcome'));
        $payload = DB::table('jobs')->value('payload');
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert(['uuid' => $id, 'connection' => 'database', 'queue' => 'default', 'payload' => $payload, 'exception' => 'Synthetic failure', 'failed_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/settings/retry-mail', ['id' => $id])->assertRedirect();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_welcome_delivery_is_deduplicated_and_manual_resend_is_available(): void
    {
        Mail::fake();
        $a = $this->application();
        (new SendTalentStageEmail($a->id, 'welcome'))->handle();
        (new SendTalentStageEmail($a->id, 'welcome'))->handle();
        Mail::assertSentCount(1);
        (new SendTalentStageEmail($a->id, 'welcome', '', true))->handle();
        Mail::assertSentCount(2);
        (new SendTalentStageEmail($a->id, 'onboarding'))->handle();
        Mail::assertSentCount(2);
    }

    use RefreshDatabase;

    private function application($user = null)
    {
        return TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Sample Applicant', 'email' => 'sample@example.test', 'country' => 'Pakistan', 'applicant_type' => 'individual', 'user_id' => $user?->id, 'status' => 'under_review']);
    }

    public function test_approval_requires_completed_details_and_only_manual_approval_queues_invitation()
    {
        Queue::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);
        $a = $this->application();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/admin/applications/'.$a->id.'/review', ['action' => 'approve_onboarding', 'expected_status' => 'under_review'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $a->update(['profile' => ['service_stage_completed_at' => now()->toIso8601String()]]);
        $this->postJson('/admin/applications/'.$a->id.'/review', ['action' => 'approve_onboarding', 'expected_status' => 'under_review'])->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $a->id, 'status' => 'invited']);
        Queue::assertPushed(SendTalentStageEmail::class, fn ($job) => $job->stage === 'onboarding');
        $this->get(URL::temporarySignedRoute('talent.onboarding', now()->addHour(), ['reference' => $a->reference]))->assertOk();
    }

    public function test_settings_are_encrypted_and_secrets_are_not_shown()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/settings', ['smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_password' => 'private-test-secret', 'from_email' => 'team@example.test', 'from_name' => 'The Linguals', 'recaptcha_secret_key' => 'captcha-private'])->assertRedirect();
        $raw = DB::table('portal_settings')->value('value');
        $this->assertStringNotContainsString('private-test-secret', $raw);
        $this->assertSame('private-test-secret', PortalSettings::read('integrations')['smtp_password']);
        $this->get('/admin/settings')->assertOk()->assertDontSee('private-test-secret')->assertDontSee('captcha-private');
        $this->actingAs(User::factory()->create())->get('/admin/settings')->assertForbidden();
    }

    public function test_document_is_private_and_internal_checks_never_claim_authenticity()
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $a = $this->application($owner);
        Storage::disk('local')->put('docs/sample.doc', 'Sample Applicant');
        $id = DB::table('documents')->insertGetId(['application_id' => $a->id, 'category' => 'CV', 'original_name' => 'sample.doc', 'disk' => 'local', 'path' => 'docs/sample.doc', 'mime' => 'application/msword', 'bytes' => 16, 'sha256' => hash('sha256', 'Sample Applicant'), 'version' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/documents/'.$id.'/file')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/documents/'.$id.'/file')->assertForbidden();
        $this->actingAs($owner)->get('/documents/'.$id)->assertOk();
        $this->post('/admin/documents/'.$id.'/assess')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/documents/'.$id.'/assess')->assertRedirect();
        $this->assertDatabaseHas('document_assessments', ['document_id' => $id, 'outcome' => 'manual_review_required', 'provider' => 'internal_consistency']);
        $this->get('/documents/'.$id)->assertSee('Authenticity is not verified');
    }

    public function test_image_is_resized_and_compression_preserves_original_cv()
    {
        Storage::fake('local');
        $path = PrivateFiles::photo(UploadedFile::fake()->image('profile.png', 2000, 1200));
        $size = getimagesize(Storage::disk('local')->path($path));
        $this->assertSame(1600, $size[0]);
        $this->assertSame('image/jpeg', $size['mime']);
        $bytes = str_repeat('original CV content ', 200000);
        $file = UploadedFile::fake()->createWithContent('cv.pdf', $bytes);
        $metadata = PrivateFiles::store($file);
        $this->assertSame('gzip', $metadata['encoding']);
        $this->assertLessThan(strlen($bytes), $metadata['bytes']);
        $this->assertSame($bytes, PrivateFiles::bytes($metadata));
    }

    public function test_template_requires_the_next_step_link_and_escapes_html()
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/email-templates/welcome', ['subject' => 'Welcome', 'body' => 'No next step'])->assertSessionHasErrors('body');
        $this->post('/admin/email-templates/welcome', ['subject' => 'Welcome', 'body' => '<script>alert(1)</script> {{link}}'])->assertRedirect();
        $this->get('/admin/email-templates/welcome/preview')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }
}
