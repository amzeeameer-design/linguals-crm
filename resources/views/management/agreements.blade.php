@extends('layout')
@section('content')
<div class="modern-management" data-management-tabs data-initial-tab="{{ $errors->any() ? 'request' : 'agreements' }}">
<div class="heading"><div><div class="eyebrow">ONBOARDING · AGREEMENTS</div><h1>Agreements</h1><p>{{ $admin?'Manage templates, request signatures and review signed copies.':'Review and sign your agreements with The Linguals.' }}</p></div></div>
<div class="modern-tabs" role="tablist" aria-label="Agreement sections"><button role="tab" type="button" id="agreements-tab" data-tab="agreements" aria-controls="agreements-panel" aria-selected="true">Agreements</button>@if($admin)<button role="tab" type="button" id="templates-tab" data-tab="templates" aria-controls="templates-panel" aria-selected="false" tabindex="-1">Templates <span>{{ count($templates) }}</span></button><button role="tab" type="button" id="request-tab" data-tab="request" aria-controls="request-panel" aria-selected="false" tabindex="-1">+ Request signature</button>@endif</div><section id="agreements-panel" role="tabpanel" aria-labelledby="agreements-tab" data-tab-panel="agreements"><div class="agreement-status-strip">
@foreach(['issued'=>'Awaiting signature','submitted'=>'Awaiting review','signed'=>'Completed'] as $key=>$label)
<a href="{{ request()->url().'?status='.$key }}" class="{{ $status===$key?'active':'' }}"><strong>{{ $counts[$key]??0 }}</strong><span>{{ $label }}</span></a>
@endforeach
</div>
<div class="agreement-toolbar"><form method="get"><input name="q" value="{{ $search }}" placeholder="Search applicant" aria-label="Search agreement applicant"><input type="hidden" name="status" value="{{ $status }}"><button>Search</button>
@if($status||$search)
<a class="button" href="{{ request()->url() }}">Clear</a>
@endif
</form>

</div>

<div class="agreement-list">
@forelse($agreements as $agreement)
@php
$evidence=json_decode($agreement->signing_evidence,true)??[];
$electronic=isset($evidence['snapshot']);
@endphp
<details class="panel agreement-card"><summary><div><strong>{{ $agreement->name }}</strong><small>{{ $evidence['snapshot']['title']??'Uploaded NDA' }} · v{{ $agreement->template_version }} · {{ Illuminate\Support\Carbon::parse($agreement->issued_at)->format('d M Y') }}</small></div><span class="badge">{{ ['issued'=>'Awaiting signature','submitted'=>'Awaiting review','signed'=>'Completed'][$agreement->status]??$agreement->status }}</span></summary><div class="agreement-body"><div class="agreement-actions"><a class="button" href="{{ url('agreements/'.$agreement->id.'/file') }}" target="_blank" rel="noopener">View agreement ↗</a>
@if(in_array($agreement->status,['submitted','signed']))
<a class="button" href="{{ url('agreements/'.$agreement->id.'/file').'?signed=1' }}" target="_blank" rel="noopener">Signed copy ↗</a>
@endif
@if(!$admin&&$agreement->status==='issued'&&$electronic)
<a class="button primary" href="{{ url('agreements/'.$agreement->id.'/sign') }}">Review & sign →</a>
@endif
</div>
@if(isset($evidence['signature']))
<div class="agreement-signature-meta"><strong>Electronically signed by {{ $evidence['signature']['name'] }}</strong><small>{{ $evidence['signature']['signed_at'] }} · Authenticated applicant account</small><details><summary>Document integrity record</summary><p class="document-hash">SHA-256: {{ $evidence['signature']['document_hash'] }}</p></details></div>
@endif
@if(!$admin&&$agreement->status==='issued'&&!$electronic)
<form method="post" enctype="multipart/form-data" action="{{ url('agreements/'.$agreement->id.'/submit') }}">
@csrf
<label>Signed PDF for this previously issued agreement<input name="file" type="file" accept="application/pdf" required></label><button class="primary">Submit signed copy</button></form>
@endif
@if($admin&&$agreement->status==='submitted')
<form method="post" action="{{ url('admin/agreements/'.$agreement->id.'/review') }}" class="agreement-review-form">
@csrf
<label>Decision<select name="decision"><option value="signed">Accept signed copy</option><option value="issued">Request replacement</option></select></label><label>Internal reason<textarea name="note" required maxlength="3000" rows="2"></textarea></label><button class="primary">Save review</button></form>
@endif
</div></details>
@empty
<div class="panel agreement-empty"><h2>{{ $search||$status?'No matching agreements':'No agreements yet' }}</h2><p>{{ $admin?'Request a signature from an onboarded applicant to begin.':'Your agreements will appear here when the team issues them.' }}</p></div>
@endforelse
</div><div class="notification-pagination">
@if($agreements->previousPageUrl())
<a href="{{ $agreements->previousPageUrl() }}">← Previous</a>
@endif
<span>{{ $agreements->currentPage() }} / {{ $agreements->lastPage() }}</span>
@if($agreements->nextPageUrl())
<a href="{{ $agreements->nextPageUrl() }}">Next →</a>
@endif
</div>

</section>@if($admin)<section id="templates-panel" role="tabpanel" aria-labelledby="templates-tab" data-tab-panel="templates" hidden><div class="modern-section-heading"><div><h2>NDA template library</h2><p>Edit reusable templates or preview the branded PDF.</p></div><a class="button" href="{{ url('admin/agreements/templates/new') }}">+ New template</a></div><div class="agreement-template-grid">
@foreach($templates as $id=>$item)
<details class="modern-template"><summary><span class="modern-template-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 3H6v18h12V7zM14 3v5h5M9 12h6M9 16h6"/></svg></span><span class="modern-template-title"><span class="badge">{{ $item['version'] }}</span><h3>{{ $item['name'] }}</h3></span><span class="modern-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span></summary><div class="modern-template-body"><p>{{ $item['title'] }}</p><div><a class="button" href="{{ url('admin/agreements/templates/'.$id) }}">Edit template</a><a class="button" target="_blank" rel="noopener" href="{{ url('admin/agreements/templates/'.$id.'/preview') }}">Preview PDF ↗</a></div></div></details>
@endforeach
</div></section><section id="request-panel" role="tabpanel" aria-labelledby="request-tab" data-tab-panel="request" hidden><div class="panel modern-request"><div class="modern-section-icon">↗</div><h2>Send an agreement to the portal</h2><form method="post" action="{{ url('admin/agreements/issue') }}">
@csrf
<label>Applicant<select name="application_id" required><option value="">Choose an onboarded applicant</option>
@foreach($applications as $person)
<option value="{{ $person->id }}">{{ $person->name }} · {{ $person->email }}</option>
@endforeach
</select></label><label>Template<select name="template_id" required>
@foreach($templates as $id=>$item)
<option value="{{ $id }}">{{ $item['name'] }} · {{ $item['version'] }}</option>
@endforeach
</select></label><p>A private agreement copy and portal notification are created. Email remains a manual action.</p><button class="primary">Request signature</button></form></div></section>@endif</div>
<link rel="stylesheet" href="{{ asset('management-modern.css') }}?v={{ filemtime(public_path('management-modern.css')) }}"><script src="{{ asset('management-modern.js') }}?v={{ filemtime(public_path('management-modern.js')) }}" defer></script>
@endsection