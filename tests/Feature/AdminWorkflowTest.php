<?php

namespace Tests\Feature;

use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function application()
    {
        return TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Test Applicant', 'email' => 'talent@example.test', 'applicant_type' => 'individual', 'status' => 'submitted']);
    }

    private function admin()
    {
        $u = User::factory()->create();
        $u->role = 'admin';
        $u->save();

        return $u;
    }

    public function test_guests_and_applicants_cannot_access_admin(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_review_is_recorded_and_invalid_transition_is_blocked(): void
    {
        $a = $this->application();
        $this->actingAs($this->admin())->post('/admin/applications/'.$a->id.'/review', ['action' => 'acknowledge', 'expected_status' => 'submitted'])->assertRedirect();
        $this->assertDatabaseHas('applications', ['id' => $a->id, 'status' => 'under_review']);
        $this->assertDatabaseHas('review_events', ['application_id' => $a->id, 'action' => 'acknowledge']);
        $this->post('/admin/applications/'.$a->id.'/review', ['action' => 'acknowledge', 'expected_status' => 'under_review'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('review_events', 1);
    }

    public function test_rejection_requires_reason_and_stale_review_is_rejected(): void
    {
        $a = $this->application();
        $this->actingAs($this->admin())->post('/admin/applications/'.$a->id.'/review', ['action' => 'reject', 'expected_status' => 'submitted'])->assertSessionHasErrors('note');
        $this->post('/admin/applications/'.$a->id.'/review', ['action' => 'reject', 'note' => 'Incomplete', 'expected_status' => 'under_review'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('review_events', 0);
    }

    public function test_combined_filters_and_csv_follow_same_scope(): void
    {
        $a = $this->application();
        $a->services()->create(['service' => 'Translation']);
        TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Other Person', 'email' => 'other@example.test', 'applicant_type' => 'company']);
        $this->actingAs($this->admin())->get('/admin?applicant_type=individual&service=Translation')->assertOk()->assertSee('Test Applicant')->assertDontSee('Other Person');
        $r = $this->get('/admin/export?applicant_type=individual&service=Translation');
        $this->assertStringContainsString('Test Applicant', $r->streamedContent());
        $this->assertStringNotContainsString('Other Person', $r->streamedContent());
    }

    public function test_csv_neutralizes_formula_and_profile_escapes_html(): void
    {
        $a = $this->application();
        $a->update(['name' => '=1+2', 'profile' => ['about' => '<script>alert(1)</script>']]);
        $this->actingAs($this->admin());
        $this->assertStringContainsString("'=1+2", $this->get('/admin/export')->streamedContent());
        $this->get('/admin/applications/'.$a->id)->assertRedirect(url('workspace').'#/admin/review/'.$a->id);
        $this->get('/workspace')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_migration_can_resume_without_destroying_existing_records(): void
    {
        $a = $this->application();
        \Illuminate\Support\Facades\Schema::dropIfExists('review_events');
        \Illuminate\Support\Facades\Schema::table('service_rates', fn ($t) => $t->dropUnique('service_rates_service_activity_unit_currency_unique'));
        \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration', '2026_10_04_000001_create_talent_tables')->delete();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('applications', ['id' => $a->id, 'email' => $a->email]);
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('review_events'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('service_rates', 'service_rates_service_activity_unit_currency_unique'));
    }
}
