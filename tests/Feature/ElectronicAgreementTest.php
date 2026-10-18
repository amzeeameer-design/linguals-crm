<?php

namespace Tests\Feature;

use App\Models\TalentApplication;
use App\Models\User;
use App\Services\PortalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ElectronicAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_are_editable_but_issued_documents_are_immutable_and_owned(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = TalentApplication::create(['reference' => (string) Str::uuid(), 'user_id' => $applicant->id, 'name' => $applicant->name, 'email' => $applicant->email, 'applicant_type' => 'individual', 'status' => 'onboarded']);
        $this->actingAs($admin)->get('/admin/agreements')->assertOk()->assertSee('NDA template library');
        $this->get('/admin/agreements/templates/contractor-nda-2026')->assertOk()->assertSee('Agreement clauses');
        $this->get('/admin/agreements/templates/contractor-nda-2026/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/admin/agreements/issue', ['application_id' => $application->id, 'template_id' => 'contractor-nda-2026'])->assertRedirect();
        $agreement = DB::table('agreements')->first();
        $evidence = json_decode($agreement->signing_evidence, true);
        $this->assertStringContainsString($application->name, $evidence['snapshot']['body']);
        $this->assertDatabaseHas('portal_messages', ['application_id' => $application->id, 'title' => 'Your agreement is ready to sign']);
        $templates = config('agreements.templates');
        $template = $templates['contractor-nda-2026'];
        $template['body'] = 'Changed template for a future agreement.';
        $template['version'] = '2026.2';
        $this->post('/admin/agreements/templates/contractor-nda-2026', $template)->assertRedirect();
        $this->assertSame('2026.2', PortalSettings::read('nda_templates')['contractor-nda-2026']['version']);
        $this->assertSame($agreement->signing_evidence, DB::table('agreements')->value('signing_evidence'));
        $this->post('/admin/agreements/issue', ['application_id' => $application->id, 'template_id' => 'contractor-nda-2026'])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'applicant']))->get('/agreements/'.$agreement->id.'/sign')->assertForbidden();
        $this->actingAs($applicant)->get('/agreements/'.$agreement->id.'/sign')->assertOk()->assertSee('Your electronic signature');
        $this->get('/admin/agreements/templates/contractor-nda-2026')->assertForbidden();
        $this->post('/agreements/'.$agreement->id.'/submit')->assertStatus(409);
        $data = ['name' => $applicant->name, 'title' => 'Independent contractor', 'consent' => 1, 'document_hash' => str_repeat('0', 64)];
        $this->post('/agreements/'.$agreement->id.'/sign', $data)->assertStatus(409);
        $data['document_hash'] = $evidence['template']['sha256'];
        $data['consent'] = 0;
        $this->post('/agreements/'.$agreement->id.'/sign', $data)->assertSessionHasErrors('consent');
        $data['consent'] = 1;
        $this->post('/agreements/'.$agreement->id.'/sign', $data)->assertRedirect('/portal/agreements');
        $signed = json_decode(DB::table('agreements')->value('signing_evidence'), true);
        $this->assertSame($applicant->id, $signed['signature']['user_id']);
        $this->assertSame($evidence['template']['sha256'], $signed['signature']['document_hash']);
        $this->assertDatabaseHas('agreements', ['id' => $agreement->id, 'status' => 'submitted']);
        $this->assertSame('submitted', $application->fresh()->nda_status);
        $this->get('/agreements/'.$agreement->id.'/file?signed=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/agreements/'.$agreement->id.'/sign', $data)->assertStatus(409);
        $this->actingAs($admin)->post('/admin/agreements/'.$agreement->id.'/review', ['decision' => 'signed', 'note' => 'Reviewed electronic signature and document.'])->assertRedirect();
        $this->assertSame('signed', $application->fresh()->nda_status);
    }
}
