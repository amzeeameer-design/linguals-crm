<?php

namespace Tests\Feature;

use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalOperationsTest extends TestCase
{
    public function test_profile_preserves_proficiency_and_rejects_duplicate_languages(): void
    {
        [$u,$a] = $this->setupApplicant();
        $data = ['name' => 'Applicant', 'email' => $u->email, 'country' => 'Pakistan', 'timezone' => 'Asia/Karachi', 'applicant_type' => 'individual', 'language_entries' => [['language' => 'English', 'proficiency' => 'C1'], ['language' => 'Urdu', 'proficiency' => 'Native']]];
        $this->actingAs($u)->postJson('/portal/profile', $data)->assertOk();
        $this->assertDatabaseHas('application_languages', ['application_id' => $a->id, 'language' => 'Urdu', 'proficiency' => 'Native']);
        $data['language_entries'][1] = ['language' => 'english', 'proficiency' => 'B1'];
        $this->postJson('/portal/profile', $data)->assertUnprocessable();
        $this->assertDatabaseCount('application_languages', 2);
    }

    use RefreshDatabase;

    private function setupApplicant()
    {
        $u = User::factory()->create();
        $a = TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Applicant', 'email' => $u->email, 'applicant_type' => 'individual', 'user_id' => $u->id, 'status' => 'active']);

        return [$u, $a];
    }

    public function test_service_pairs_rates_and_experience_persist()
    {
        [$u,$a] = $this->setupApplicant();
        $this->actingAs($u)->postJson('/portal/services', ['services' => ['Translation'], 'serviceDetails' => ['Translation' => ['experience' => 5, 'pairs' => [['source' => 'English', 'target' => 'Urdu', 'both' => true]], 'rates' => [['activity' => 'Translation', 'amount' => '0.12', 'unit' => 'word', 'currency' => 'USD']], 'special0' => 'memoQ']]])->assertOk();
        $this->assertDatabaseHas('language_pairs', ['source_language' => 'English', 'target_language' => 'Urdu', 'both_ways' => 1]);
        $this->assertDatabaseHas('service_rates', ['activity' => 'Translation', 'amount' => 0.12]);
        $this->get('/portal')->assertViewHas('workspace', fn ($p) => count($p['application']['services'][0]['rates']) === 1);
        $this->postJson('/portal/services', ['services' => ['Not a service']])->assertUnprocessable();
    }

    public function test_profile_and_password_updates_require_owner_and_current_password()
    {
        [$u,$a] = $this->setupApplicant();
        $this->actingAs($u)->postJson('/portal/profile', ['name' => 'Updated name', 'email' => $u->email, 'country' => 'Pakistan', 'timezone' => 'Asia/Karachi', 'applicant_type' => 'individual', 'domains' => 'Legal, Medical'])->assertOk();
        $this->assertDatabaseHas('applications', ['id' => $a->id, 'name' => 'Updated name']);
        $this->postJson('/portal/password', ['current' => 'wrong', 'new' => 'NewExamplePassword123!', 'confirm' => 'NewExamplePassword123!'])->assertUnprocessable();
        $this->postJson('/portal/password', ['current' => 'password', 'new' => 'NewExamplePassword123!', 'confirm' => 'NewExamplePassword123!'])->assertOk();
        $this->assertTrue(Hash::check('NewExamplePassword123!', $u->fresh()->password));
    }

    public function test_messages_are_scoped_and_escaped()
    {
        [$u,$a] = $this->setupApplicant();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/notifications', ['application_id' => $a->id, 'title' => 'Profile update', 'body' => '<script>alert(1)</script>'])->assertRedirect();
        $this->actingAs($u);
        $this->get('/portal/notifications')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs(User::factory()->create())->post('/portal/messages/'.$a->id, ['body' => 'Unauthorised'])->assertForbidden();
        $this->assertDatabaseCount('portal_messages', 1);
    }

    public function test_nda_is_issued_versioned_private_and_manually_reviewed()
    {
        Storage::fake('local');
        [$u,$a] = $this->setupApplicant();
        $admin = User::factory()->create(['role' => 'admin']);
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";
        $this->actingAs($admin)->post('/admin/agreements/template', ['version' => 'v1', 'file' => UploadedFile::fake()->createWithContent('nda.pdf', $pdf)])->assertRedirect();
        $this->post('/admin/agreements/issue', ['application_id' => $a->id])->assertRedirect();
        $id = DB::table('agreements')->value('id');
        $this->actingAs(User::factory()->create())->get('/agreements/'.$id.'/file')->assertForbidden();
        $this->actingAs($u)->get('/portal/agreements')->assertOk();
        $this->post('/agreements/'.$id.'/submit', ['file' => UploadedFile::fake()->createWithContent('signed.pdf', $pdf)])->assertRedirect();
        $this->assertDatabaseHas('agreements', ['id' => $id, 'status' => 'submitted']);
        $this->assertNotSame('signed', $a->fresh()->nda_status);
        $this->actingAs($admin)->post('/admin/agreements/'.$id.'/review', ['decision' => 'signed', 'note' => 'Manually reviewed signed copy.'])->assertRedirect();
        $this->assertSame('signed', $a->fresh()->nda_status);
    }

    public function test_applicant_cannot_edit_admin_fields_or_make_kyc_decision()
    {
        [$u,$a] = $this->setupApplicant();
        $this->actingAs($u)->get('/admin/applications/'.$a->id.'/edit')->assertForbidden();
        $this->postJson('/admin/applications/'.$a->id.'/kyc', ['decision' => 'approved', 'reason' => 'test'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson('/admin/applications/'.$a->id.'/kyc', ['decision' => 'approved', 'reason' => 'Documents manually checked'])->assertOk();
        $this->assertDatabaseHas('review_events', ['action' => 'kyc_approved']);
    }
}
