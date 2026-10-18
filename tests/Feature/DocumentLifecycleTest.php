<?php

namespace Tests\Feature;

use App\Models\TalentApplication;
use App\Models\User;
use App\Services\DocumentPreview;
use App\Services\PrivateFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function application(User $owner): TalentApplication
    {
        return TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'José Applicant', 'email' => $owner->email, 'applicant_type' => 'individual', 'user_id' => $owner->id, 'country' => 'Pakistan', 'status' => 'active']);
    }

    private function word(string $text): UploadedFile
    {
        $path = Storage::disk('local')->path('word-'.Str::uuid().'.docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('sample.docx', file_get_contents($path));
        unlink($path);

        return $file;
    }

    public function test_word_pdf_and_image_previews_stay_private_and_keep_originals(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $file = PrivateFiles::store($this->word('José Applicant French experience sample@example.test'));
        $id = PrivateFiles::register($application->id, 'CV', $file);
        $this->get('/documents/'.$id.'/pdf')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/documents/'.$id.'/pdf')->assertForbidden();
        $pdf = $this->actingAs($owner)->get('/documents/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('José Applicant', (new Parser)->parseContent($pdf->getContent())->getText());
        $original = $this->get('/documents/'.$id.'/file?download=1')->assertOk();
        $this->assertSame(PrivateFiles::bytes($file), $original->getContent());
        $this->get('/documents/'.$id)->assertOk()->assertSee('data-pdf-viewer', false)->assertSee('PDF text preview');
        $image = PrivateFiles::store(UploadedFile::fake()->image('certificate.png', 900, 1200));
        $imageId = PrivateFiles::register($application->id, 'Certificate', $image);
        $preview = $this->get('/documents/'.$imageId.'/pdf')->assertOk();
        $this->assertCount(1, (new Parser)->parseContent($preview->getContent())->getPages());
    }

    public function test_corrupt_original_is_flagged_and_cannot_be_previewed_or_accepted(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $file = PrivateFiles::store($this->word('José Applicant authentic storage copy'));
        $id = PrivateFiles::register($application->id, 'CV', $file);
        Storage::disk('local')->put($file['path'], 'tampered bytes');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/documents/'.$id.'/pdf')->assertStatus(409);
        $this->post('/admin/documents/'.$id.'/review', ['status' => 'accepted', 'note' => 'Checking integrity'])->assertStatus(409);
        $this->post('/admin/documents/'.$id.'/assess')->assertRedirect();
        $assessment = DB::table('document_assessments')->first();
        $findings = collect(json_decode($assessment->findings, true));
        $this->assertSame('review', $findings->firstWhere('field', 'Storage integrity')['result']);
        $this->assertSame('manual_review_required', $assessment->outcome);
        $this->assertFalse(json_decode($assessment->coverage, true)['integrity']);
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'pending']);
    }

    public function test_library_is_scoped_versions_are_retained_and_archive_is_reversible(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $this->actingAs($owner)->postJson('/portal/documents', ['category' => 'Certificate', 'document' => UploadedFile::fake()->image('first.png')])->assertOk();
        $this->postJson('/portal/documents', ['category' => 'Certificate', 'document' => UploadedFile::fake()->image('second.png')])->assertOk();
        $first = DB::table('documents')->orderBy('id')->first();
        $second = DB::table('documents')->orderByDesc('id')->first();
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_id);
        $other = User::factory()->create();
        $otherApplication = $this->application($other);
        $file = PrivateFiles::store(UploadedFile::fake()->image('other-private.png'));
        PrivateFiles::register($otherApplication->id, 'Identity', $file);
        $this->get('/portal/files')->assertOk()->assertSee('first.png')->assertDontSee('other-private.png');
        $this->post('/documents/'.$first->id.'/archive', ['action' => 'archive', 'note' => 'New version supplied'])->assertRedirect();
        $this->get('/portal/files')->assertDontSee('first.png')->assertSee('second.png');
        $this->get('/portal/files?archived=1')->assertSee('first.png');
        $this->get('/portal')->assertViewHas('workspace', fn (array $payload): bool => count($payload['documents']) === 1);
        Storage::disk('local')->assertExists($first->path);
        $this->post('/documents/'.$first->id.'/archive', ['action' => 'restore', 'note' => 'Restoring earlier evidence'])->assertRedirect();
        $this->get('/portal/files')->assertSee('first.png');
        DB::table('documents')->where('id', $first->id)->update(['status' => 'accepted']);
        $this->post('/documents/'.$first->id.'/archive', ['action' => 'archive', 'note' => 'Hide accepted evidence'])->assertForbidden();
        $this->get('/admin/files')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/files')->assertOk()->assertSee('other-private.png');
    }

    public function test_assessment_flags_duplicates_and_email_conflicts_without_claiming_authenticity(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $file = PrivateFiles::store($this->word('José Applicant professional translator different@example.test French 2099'));
        $id = PrivateFiles::register($application->id, 'CV', $file);
        PrivateFiles::register($this->application(User::factory()->create())->id, 'CV', $file);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/documents/'.$id.'/assess')->assertRedirect();
        $assessment = DB::table('document_assessments')->first();
        $findings = collect(json_decode($assessment->findings, true));
        $this->assertSame('review', $findings->firstWhere('field', 'Duplicate file')['result']);
        $this->assertSame('review', $findings->firstWhere('field', 'Email discrepancy')['result']);
        $this->assertSame('found', $findings->firstWhere('field', 'name')['result']);
        $this->assertSame('review', $findings->firstWhere('field', 'Future dates')['result']);
        $this->assertSame('not_checked', $findings->firstWhere('field', 'Originality assessment')['result']);
    }

    public function test_onboarded_service_form_accepts_sample_and_retains_account_status(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $data = ['services' => ['Translation'], 'consent' => 1, 'details' => ['translation' => ['source' => 'English', 'target' => 'Urdu', 'speciality' => 'Legal', 'experience' => 5, 'language' => 'Urdu', 'proficiency' => 'Native']], 'service_samples' => ['translation' => UploadedFile::fake()->image('translation-sample.png')]];
        $this->actingAs($owner)->get('/portal/service-details')->assertOk()->assertSee('Upload a sample document');
        $this->post('/portal/service-details', $data)->assertRedirect('/portal#/user/services');
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'active', 'user_id' => $owner->id]);
        $this->assertDatabaseHas('documents', ['application_id' => $application->id, 'category' => 'Sample']);
        $service = $application->services()->first();
        $this->assertNotEmpty($service->details['sample_document_id']);
        $this->postJson('/portal/services', ['services' => ['Translation'], 'serviceDetails' => ['Translation' => ['experience' => 6, 'rates' => []]]])->assertOk();
        $this->assertSame('Legal', $service->fresh()->details['speciality']);
        $this->assertNotEmpty($service->fresh()->details['sample_document_id']);
        $this->assertTrue($application->fresh()->profile['service_update_requires_review']);
    }

    public function test_readiness_is_admin_only_and_assets_are_registered(): void
    {
        $this->get('/admin/readiness')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/readiness')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/readiness')->assertOk()->assertSee('Launch readiness')->assertSee('Mail queue and cron');
    }

    public function test_document_upload_queues_internal_assessment_and_manual_service_review_notifies_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $file = PrivateFiles::store(UploadedFile::fake()->image('sample.png'));
        $id = PrivateFiles::register($application->id, 'Certificate', $file);
        $this->assertDatabaseHas('jobs', ['queue' => 'documents']);
        (new \App\Jobs\AssessDocument($id))->handle();
        $this->assertDatabaseHas('document_assessments', ['document_id' => $id, 'outcome' => 'manual_review_required']);
        $application->update(['profile' => ['service_update_requires_review' => true]]);
        $this->actingAs($owner)->post('/admin/applications/'.$application->id.'/service-review', ['decision' => 'accepted', 'note' => 'Reviewed details'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/applications/'.$application->id.'/service-review')->assertOk();
        $this->post('/admin/applications/'.$application->id.'/service-review', ['decision' => 'accepted', 'note' => 'Reviewed details'])->assertRedirect();
        $this->assertFalse($application->fresh()->profile['service_update_requires_review']);
        $this->assertSame('active', $application->fresh()->status);
        $this->assertDatabaseHas('portal_messages', ['application_id' => $application->id, 'body' => 'Reviewed details']);
    }

    public function test_preferences_persist_and_identity_changes_trigger_reverification(): void
    {
        $owner = User::factory()->create();
        $application = $this->application($owner);
        $application->update(['kyc_status' => 'approved']);
        $this->actingAs($owner)->postJson('/portal/preferences', ['updates' => true, 'reminders' => false, 'newsletter' => false])->assertOk();
        $this->assertFalse($application->fresh()->profile['notification_preferences']['reminders']);
        $this->postJson('/portal/profile', ['name' => 'Updated legal name', 'email' => $owner->email, 'country' => 'Pakistan', 'timezone' => 'Asia/Karachi', 'applicant_type' => 'individual'])->assertOk();
        $this->assertSame('pending', $application->fresh()->kyc_status);
        $this->assertDatabaseHas('review_events', ['application_id' => $application->id, 'action' => 'identity_details_changed']);
    }

    public function test_portable_deployment_urls_include_configured_subfolder(): void
    {
        $owner = User::factory()->create();
        $application = $this->application($owner);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://talent.example.test/team/portal');
        \Illuminate\Support\Facades\URL::forceScheme('https');
        $this->withServerVariables(['SCRIPT_NAME' => '/team/portal/index.php', 'PHP_SELF' => '/team/portal/index.php', 'SCRIPT_FILENAME' => public_path('index.php')]);
        $this->actingAs($owner)->get('/portal')->assertOk()->assertSee('https://talent.example.test/team/portal', false);
        $this->get('/portal/service-details')->assertOk()->assertSee('https://talent.example.test/team/portal/service-stage.js', false);
        $this->assertStringStartsWith('https://talent.example.test/team/portal/apply/services/', URL::temporarySignedRoute('talent.details', now()->addHour(), ['reference' => $application->reference]));
        \Illuminate\Support\Facades\URL::forceRootUrl(null);
        \Illuminate\Support\Facades\URL::forceScheme(null);
    }
}
