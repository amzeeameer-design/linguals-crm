<?php

namespace Tests\Feature;

use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_requires_an_administrator(): void
    {
        $this->get('/workspace')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/workspace')->assertForbidden();
        $admin = User::factory()->create();
        $admin->role = 'admin';
        $admin->save();
        $this->actingAs($admin)->get('/workspace')->assertOk()->assertSee('workspace.js');
        $this->get('/portal')->assertForbidden();
        $this->get('/')->assertRedirect('/workspace');
    }

    public function test_applicant_payload_only_contains_their_application(): void
    {
        $user = User::factory()->create();
        $own = TalentApplication::create([
            'reference' => (string) Str::uuid(), 'user_id' => $user->id,
            'name' => 'OwnApplicant', 'email' => $user->email,
            'applicant_type' => 'individual', 'profile' => ['about' => '<script>alert(1)</script>'],
        ]);
        TalentApplication::create([
            'reference' => (string) Str::uuid(), 'name' => 'OtherPrivateApplicant',
            'email' => 'other@example.test', 'applicant_type' => 'individual',
        ]);
        $response = $this->actingAs($user)->get('/portal');
        $response->assertOk()->assertSee('OwnApplicant')->assertDontSee('OtherPrivateApplicant');
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertViewHas('workspace', fn ($data) =>
            $data['role'] === 'user' && $data['application']['id'] === $own->id
            && ! isset($data['application']['events']) && ! isset($data['applications'])
        );
    }

    public function test_applicant_sign_in_opens_their_portal(): void
    {
        $user = User::factory()->create(['password' => 'AnExamplePassword123!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'AnExamplePassword123!'])
            ->assertRedirect('/portal');
        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect('/portal');
    }

    public function test_administrator_payload_includes_structured_talent_search_data(): void
    {
        $admin = User::factory()->create();
        $admin->role = 'admin';
        $admin->save();
        $application = TalentApplication::create([
            'reference' => (string) Str::uuid(), 'name' => 'SearchableTalent',
            'email' => 'search@example.test', 'applicant_type' => 'individual',
            'profile' => ['city' => 'Paris', 'company_size' => '12'],
        ]);
        $service = $application->services()->create(['service' => 'Translation', 'experience_years' => 7]);
        \Illuminate\Support\Facades\DB::table('application_languages')->insert([
            'application_id' => $application->id, 'language' => 'French', 'proficiency' => 'Native',
        ]);
        \Illuminate\Support\Facades\DB::table('language_pairs')->insert([
            'application_service_id' => $service->id, 'source_language' => 'English', 'target_language' => 'French',
        ]);
        \Illuminate\Support\Facades\DB::table('service_domains')->insert([
            'application_service_id' => $service->id, 'domain' => 'Legal',
        ]);
        \Illuminate\Support\Facades\DB::table('service_rates')->insert([
            'application_service_id' => $service->id, 'activity' => 'Translation',
            'unit' => 'word', 'currency' => 'USD', 'amount' => '0.12',
        ]);
        $this->actingAs($admin)->get('/workspace')->assertOk()->assertViewHas('workspace', function ($data) {
            $talent = $data['applications'][0];
            return $talent['name'] === 'SearchableTalent'
                && $talent['languages'][0]->proficiency === 'Native'
                && $talent['services'][0]['language_pairs'][0]->target_language === 'French'
                && $talent['services'][0]['domains'] === ['Legal']
                && (float) $talent['services'][0]['rates'][0]->amount === 0.12
                && $talent['profile']['city'] === 'Paris';
        });
    }
}
