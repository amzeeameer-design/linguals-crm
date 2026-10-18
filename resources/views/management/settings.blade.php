@extends('layout')

@section('content')

<div class="heading"><div><div class="eyebrow">WORKSPACE ADMINISTRATION</div><h1>Settings & email templates</h1><p>Manage delivery, integrations and applicant communication from one place.</p></div></div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px"><a class="button" href="{{ url('admin/files') }}">File library</a><a class="button" href="{{ url('admin/readiness') }}">Launch readiness</a></div><div class="settings-status-strip"><span>SMTP <b>{{ !empty($settings['smtp_host'])&&$hasPassword?'Configured':'Setup needed' }}</b></span><span>Human verification <b>{{ $hasCaptchaSecret&&!empty($settings['recaptcha_site_key'])?'Configured':'Setup needed' }}</b></span><span>Queue <b>{{ $pending }} waiting · {{ $failed }} failed</b></span></div>

<nav class="settings-navigation" aria-label="Settings sections"><button type="button" data-settings-tab="delivery">Email delivery</button><button type="button" data-settings-tab="integrations">Integrations</button><button type="button" data-settings-tab="templates">Email templates</button><button type="button" data-settings-tab="queue">Background jobs</button><button type="button" data-settings-tab="activity">Administration activity</button></nav>

<form method="post" action="{{ url('admin/settings') }}" data-settings-form id="integration-settings">

@csrf

<section class="panel" data-settings-panel="delivery"><div class="settings-section-heading"><h2>SMTP connection</h2><p>Secret values are stored encrypted. Leave saved secrets blank to keep them.</p></div><div class="settings-fields">

@foreach(['smtp_host'=>'SMTP host','smtp_username'=>'SMTP username','from_email'=>'Sender email','from_name'=>'Sender name'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$settings[$key]??($key==='from_name'?'The Linguals':'')) }}" {{ in_array($key,['from_email','from_name'])?'required':'' }} 

@if($key==='from_email') type="email" 

@endif></label>

@endforeach<label>Port<select name="smtp_port">

@foreach([587,465,25,2525] as $port)<option 

@selected(old('smtp_port',$settings['smtp_port']??587)==$port)>{{ $port }}</option>

@endforeach</select></label><label>Encryption<select name="smtp_encryption"><option value="tls" 

@selected(old('smtp_encryption',$settings['smtp_encryption']??'tls')==='tls')>TLS / STARTTLS</option><option value="ssl" 

@selected(old('smtp_encryption',$settings['smtp_encryption']??'')==='ssl')>SSL</option></select></label><label>SMTP password<input type="password" name="smtp_password" autocomplete="new-password" placeholder="{{ $hasPassword?'Saved securely; leave blank to keep':'Enter password' }}"></label></div><button class="primary">Save connection & integrations</button></section>

<section class="panel" data-settings-panel="integrations"><h2>Google reCAPTCHA</h2><p>Add the keys registered for your deployment hostname.</p><div class="settings-fields"><label>Site key<input name="recaptcha_site_key" value="{{ old('recaptcha_site_key',$settings['recaptcha_site_key']??'') }}"></label><label>Secret key<input type="password" name="recaptcha_secret_key" autocomplete="new-password" placeholder="{{ $hasCaptchaSecret?'Saved securely; leave blank to keep':'Enter secret' }}"></label><label>Country suggestion<select name="location_enabled"><option value="1" 

@selected(old('location_enabled',$settings['location_enabled']??true))>Enabled when applicant clicks</option><option value="0" 

@selected(!old('location_enabled',$settings['location_enabled']??true))>Disabled</option></select></label></div><div class="notice"><b>Private document checks</b><p>PDF and Word text checks compare submitted details and identify duplicate files within this platform. No external AI service is used. An administrator makes the final decision.</p></div><button class="primary">Save connection & integrations</button></section>

<section class="panel" data-settings-panel="delivery"><h2>Save & test email delivery</h2><p>Your current connection details are saved before testing. Use your mail provider's SMTP hostname and sender address. Port 587 uses STARTTLS; port 465 uses SSL.</p>

@if(!empty($smtpTest['tested_at']))

<div class="notice {{ ($smtpTest['status']??'')==='accepted'?'green':'' }}" role="status">Last test: {{ $smtpTest['status']==='accepted'?'Accepted by SMTP server':($smtpTest['message']??'Failed') }} · {{ $smtpTest['tested_at'] }}</div>

@endif

<div class="settings-test-row"><label>Test recipient<input type="email" name="recipient" value="{{ old('recipient') }}" placeholder="you@example.com"></label><button type="submit" name="intent" value="test">Save & send test</button></div></section></form>

<section data-settings-panel="templates" class="template-workspace"><div class="template-list panel"><h2>Email templates</h2><p>The welcome email is automatic. Other stages are sent by an admin action.</p><div role="tablist" aria-label="Email templates">

@foreach($templates as $stage=>$template)<button type="button" role="tab" data-template-tab="{{ $stage }}"><b>{{ ucwords(str_replace('_',' ',$stage)) }}</b><small>{{ $stage==='welcome'?'Automatic after initial submission':'Manual action' }}</small></button>

@endforeach</div></div><div>

@foreach($templates as $stage=>$template)<section class="panel template-editor" data-template-panel="{{ $stage }}" data-active-template="{{ old('template_stage',session('active_template','welcome')) }}"><div class="template-editor-heading"><h2>{{ ucwords(str_replace('_',' ',$stage)) }}</h2><span class="badge">{{ $stage==='welcome'?'Automatic':'Manual' }}</span></div><form method="post" action="{{ url('admin/email-templates/'.$stage) }}">

@csrf<input type="hidden" name="template_stage" value="{{ $stage }}"><label>Subject<input name="subject" required maxlength="180" value="{{ old('template_stage')===$stage?old('subject',$template['subject']):$template['subject'] }}"></label><label>Message<textarea name="body" rows="9" required maxlength="12000">{{ old('template_stage')===$stage?old('body',$template['body']):$template['body'] }}</textarea></label><div class="template-tokens"><span>Insert a token</span>

@foreach(['name','reference','link','note'] as $token)<button type="button" data-template-token="{{ $token }}">{{ '{'.'{'.$token.'}'.'}' }}</button>

@endforeach</div><small>Keep @{{link}} in welcome, onboarding and information-request templates. Formatting is plain text.</small><div class="template-footer"><button class="primary" type="submit">Save template</button><a class="button" target="_blank" rel="noopener" href="{{ url('admin/email-templates/'.$stage.'/preview') }}">Open saved email preview ↗</a></div></form><div class="email-preview"><small>LIVE PREVIEW · EXAMPLE VALUES</small><h3 data-preview-subject></h3><div class="email-preview-brand">THE LINGUALS</div><p data-preview-body></p></div></section>

@endforeach</div></section>

<section class="panel" data-settings-panel="queue"><h2>Delivery queue</h2><div class="settings-status-strip"><span><b>{{ $pending }}</b> waiting</span><span><b>{{ $failed }}</b> failed jobs</span></div><p>Messages are processed by your hosting queue worker. Retrying does not approve or create an account.</p>

@forelse($failedDeliveries as $delivery)<div class="delivery-failure"><div><b>{{ match(json_decode($delivery->payload,true)['displayName']??'') { App\Jobs\AssessDocument::class => 'Failed document assessment', App\Jobs\PurgeApplicantFiles::class => 'Failed private file cleanup', default => 'Failed applicant email' } }}</b><small>{{ $delivery->failed_at }}</small></div><form method="post" action="{{ url('admin/settings/retry-mail') }}">

@csrf<input type="hidden" name="id" value="{{ $delivery->uuid }}"><button>Retry job</button></form></div>

@empty<div class="notice">No failed background jobs to retry.</div>

@endforelse</section>

<section class="panel" data-settings-panel="activity"><h2>Administration activity</h2>

@forelse($audit as $event)<div class="delivery-failure"><b>{{ ucwords(str_replace('_',' ',$event->action)) }}</b><small>{{ $event->created_at }}</small></div>

@empty<p>No changes recorded yet.</p>

@endforelse</section>

<script src="{{ asset('settings-workspace.js') }}" defer></script>

@endsection

