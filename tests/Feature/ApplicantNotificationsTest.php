<?php

namespace Tests\Feature;

use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicantNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_one_way_owned_and_acknowledged_idempotently(): void
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Applicant', 'email' => $applicant->email, 'user_id' => $applicant->id, 'applicant_type' => 'individual', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/notifications', ['application_id' => $application->id, 'title' => 'Please review', 'body' => 'Please check your updated details.', 'requires_acknowledgement' => 1])->assertRedirect();
        $notice = DB::table('portal_messages')->first();
        $this->assertNull($notice->read_at);
        $this->actingAs($applicant)->get('/portal/notifications')->assertOk()->assertSee('Please review')->assertDontSee('Write a message');
        $this->post('/portal/messages/'.$application->id, ['body' => 'Reply'])->assertForbidden();
        $this->post('/portal/notifications/'.$notice->id.'/status', ['action' => 'acknowledge'])->assertRedirect();
        $acknowledged = DB::table('portal_messages')->first();
        $this->assertNotNull($acknowledged->read_at);
        $this->assertNotNull($acknowledged->acknowledged_at);
        $this->post('/portal/notifications/'.$notice->id.'/status', ['action' => 'acknowledge'])->assertRedirect();
        $this->assertSame($acknowledged->acknowledged_at, DB::table('portal_messages')->first()->acknowledged_at);
        $other = User::factory()->create(['role' => 'applicant']);
        TalentApplication::create(['reference' => (string) Str::uuid(), 'name' => 'Other', 'email' => $other->email, 'user_id' => $other->id, 'applicant_type' => 'individual', 'status' => 'active']);
        $this->actingAs($other)->post('/portal/notifications/'.$notice->id.'/status', ['action' => 'read'])->assertNotFound();
        $this->get('/portal/notifications')->assertDontSee('Please check your updated details.');
        $this->assertDatabaseCount('portal_messages', 1);
    }
}
