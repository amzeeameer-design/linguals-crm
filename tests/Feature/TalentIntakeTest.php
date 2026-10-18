<?php
namespace Tests\Feature;
use App\Jobs\SendTalentStageEmail;
use App\Models\TalentApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;
class TalentIntakeTest extends TestCase
{
    use RefreshDatabase;
    private function ready(): void
    {
        config(['mail.default'=>'smtp','queue.default'=>'database','talent.recaptcha_secret_key'=>'test-secret']);
        Queue::fake();
        Http::fake(['www.google.com/recaptcha/api/siteverify'=>Http::response(['success'=>true,'hostname'=>'localhost'])]);
    }
    private function basic(): array
    {
        return ['request_id'=>(string) Str::uuid(),'type'=>'individual','name'=>'Test Applicant','email'=>'applicant@example.com','country'=>'Pakistan','timezone'=>'Asia/Karachi','services'=>[],'languages'=>[['language'=>'English','proficiency'=>'B2']],'consent'=>true,'human_token'=>'test-token'];
    }
    public function test_basic_intake_allows_no_services_and_queues_one_welcome_for_retries(): void
    {
        $this->ready();$data=$this->basic();
        $this->postJson('/apply',$data)->assertAccepted();
        $this->postJson('/apply',$data)->assertAccepted();
        $this->assertDatabaseCount('applications',1);$this->assertDatabaseCount('users',0);
        Queue::assertPushed(SendTalentStageEmail::class,1);
    }
    public function test_failed_captcha_and_missing_mail_setup_do_not_save(): void
    {
        $this->ready();Http::swap(new \Illuminate\Http\Client\Factory());Http::fake(['*'=>Http::response(['success'=>false])]);
        $this->postJson('/apply',$this->basic())->assertUnprocessable();
        $this->assertDatabaseCount('applications',0);
        config(['mail.default'=>'log']);$this->postJson('/apply',$this->basic())->assertStatus(503);
        $this->assertDatabaseCount('applications',0);
    }
    public function test_service_stage_requires_a_private_signed_link_and_creates_no_account(): void
    {
        $this->ready();$data=$this->basic();$this->postJson('/apply',$data)->assertAccepted();
        $a=TalentApplication::firstOrFail();
        $this->get('/apply/services/'.$a->reference)->assertForbidden();
        $url=URL::temporarySignedRoute('talent.details',now()->addHour(),['reference'=>$a->reference]);
        $this->get($url)->assertOk();
        $this->post($url,['services'=>['Translation'],'consent'=>1,'details'=>['translation'=>['source'=>'English','target'=>'Urdu','speciality'=>'Legal translation','experience'=>3,'language'=>'Urdu','proficiency'=>'Native','rate'=>'USD 0.10 per word']], 'domains'=>['translation'=>['Legal']]])->assertOk();
        $this->assertDatabaseHas('applications',['id'=>$a->id,'status'=>'under_review','user_id'=>null]);
        $this->assertDatabaseHas('service_domains',['domain'=>'Legal']);
        Queue::assertNotPushed(SendTalentStageEmail::class,fn($job)=>$job->stage==='onboarding');
    }
    public function test_onboarding_is_blocked_until_service_details_are_complete(): void
    {
        $this->ready();$this->postJson('/apply',$this->basic())->assertAccepted();$a=TalentApplication::firstOrFail();
        $url=URL::temporarySignedRoute('talent.onboarding',now()->addHour(),['reference'=>$a->reference]);
        $this->get($url)->assertForbidden();
        $this->assertDatabaseCount('users',0);
    }
    public function test_specialist_forms_store_pairs_tools_and_comparable_rates(): void
    {
        $this->ready();
        $this->postJson('/apply', $this->basic())->assertAccepted();
        $application = TalentApplication::firstOrFail();
        $url = URL::temporarySignedRoute('talent.details', now()->addHour(), ['reference' => $application->reference]);
        $this->get($url)->assertOk()->assertSee('Your setup')->assertSee('Voice demo reel link');
        $details = [
            'translation' => ['source' => 'English', 'target' => 'Urdu', 'speciality' => 'Legal translation', 'experience' => 4, 'language' => 'Urdu', 'proficiency' => 'Native', 'cat_tools' => 'Trados, memoQ', 'rate_amount' => 0.09, 'rate_currency' => 'USD', 'rate_unit' => 'word', 'portfolio_url' => 'https://example.com/sample'],
            'interpretation' => ['languages' => 'English, Urdu', 'mode' => 'Consecutive', 'setting' => 'Medical appointments', 'experience' => 3, 'language' => 'Urdu', 'proficiency' => 'Native', 'delivery' => 'Remote', 'rate_amount' => 45, 'rate_currency' => 'USD', 'rate_unit' => 'hour'],
            'voice-over' => ['languages' => 'Urdu, English', 'voice' => 'Warm narration', 'studio' => 'Treated home studio', 'experience' => 2, 'language' => 'Urdu', 'proficiency' => 'Native', 'microphone' => 'Condenser microphone', 'rate_amount' => 30, 'rate_currency' => 'USD', 'rate_unit' => 'finished-minute', 'portfolio_url' => 'https://example.com/demo'],
        ];
        $this->post($url, ['services' => ['Translation', 'Interpretation', 'Voice Over'], 'consent' => 1, 'details' => $details, 'pairs' => ['translation' => [['source' => 'French', 'target' => 'Urdu']]]])->assertOk();
        $this->assertDatabaseHas('language_pairs', ['source_language' => 'French', 'target_language' => 'Urdu']);
        $this->assertDatabaseHas('service_rates', ['activity' => 'Translation', 'amount' => 0.09, 'currency' => 'USD', 'unit' => 'word']);
        $this->assertDatabaseHas('service_rates', ['activity' => 'Voice Over', 'amount' => 30, 'unit' => 'finished-minute']);
        $this->assertDatabaseCount('service_tools', 2);
        $details['translation']['rate_unit'] = 'invalid-unit';
        $this->post($url, ['services' => ['Translation'], 'consent' => 1, 'details' => $details])->assertSessionHasErrors('details.translation.rate_unit');
        $details['translation']['rate_unit'] = 'word';
        $details['translation']['portfolio_url'] = 'javascript:alert(1)';
        $this->post($url, ['services' => ['Translation'], 'consent' => 1, 'details' => $details])->assertSessionHasErrors('details.translation.portfolio_url');
        $this->assertDatabaseCount('users', 0);
    }
}
