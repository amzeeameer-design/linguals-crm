@extends('layout')
@section('content')
<div class="heading"><div><a href="{{ url('portal/agreements') }}">← Your agreements</a><h1>Review & sign your agreement</h1><p>Read the complete document before providing your electronic signature.</p></div><a class="button" target="_blank" rel="noopener" href="{{ url('agreements/'.$agreement->id.'/file') }}">Open PDF ↗</a></div><div class="nda-signing-layout"><article class="nda-letterhead"><header><img src="{{ asset('branding/linguals-logo.png') }}" alt="The Linguals"><span>CONFIDENTIAL · v{{ $agreement->template_version }}</span></header><h2>{{ $evidence['snapshot']['title'] }}</h2><p class="subtle">Prepared for {{ $application->name }}</p><div class="nda-clause-body">{{ $evidence['snapshot']['body'] }}</div><footer>{{ $evidence['snapshot']['footer'] }}</footer></article><div class="panel nda-signature-panel"><h2>Your electronic signature</h2>
@if($agreement->status==='issued')
<p>Type your full name as your signature. Signing records your account, date, document fingerprint and connection information.</p><form method="post" action="{{ url('agreements/'.$agreement->id.'/sign') }}">
@csrf
<input type="hidden" name="document_hash" value="{{ $evidence['template']['sha256'] }}"><label>Full legal name<input name="name" required minLength="2" maxlength="150" value="{{ old('name',$application->name) }}" autocomplete="name"></label><label>Title / role (optional)<input name="title" maxlength="150" value="{{ old('title') }}" placeholder="e.g. Independent contractor"></label><label class="notification-check"><input type="checkbox" name="consent" value="1" required><span>I have read and accept this agreement and intend my typed name to be my electronic signature.</span></label><button class="primary">Sign & submit agreement</button><small>A signed copy will be retained in your portal for admin review.</small></form>
@else
<p>Your signature has already been submitted.</p><a class="button" href="{{ url('agreements/'.$agreement->id.'/file').'?signed=1' }}" target="_blank" rel="noopener">View signed copy ↗</a>
@endif
</div></div>
@endsection
