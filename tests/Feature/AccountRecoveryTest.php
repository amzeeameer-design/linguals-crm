<?php

namespace Tests\Feature;

use App\Mail\TalentStageMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_recovery_has_generic_response_and_private_token_link(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $user = User::factory()->create();
        $response = $this->post('/forgot-password', ['email' => $user->email])->assertRedirect()->assertSessionHas('notice');
        $notice = session('notice');
        Mail::assertSent(TalentStageMail::class, 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->post('/forgot-password', ['email' => 'unknown@example.test'])->assertRedirect()->assertSessionHas('notice', $notice);
        Mail::assertSent(TalentStageMail::class, 1);
    }

    public function test_reset_requires_valid_token_and_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => 'incorrect', 'password' => 'NewStrongTestingPassword!', 'password_confirmation' => 'NewStrongTestingPassword!'];
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $data['token'] = $token;
        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_missing_smtp_never_claims_recovery_was_delivered(): void
    {
        config(['mail.default' => 'log']);
        $this->post('/forgot-password', ['email' => 'person@example.test'])->assertStatus(503);
    }
}
