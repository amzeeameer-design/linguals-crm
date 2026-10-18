@extends('layout')
@section('content')
<div class="heading"><div><a href="{{ url('admin/agreements') }}">← Agreements</a><h1>{{ $template['id']==='new'?'Create NDA template':'Edit NDA template' }}</h1><p>Save a reusable template. Issued agreements retain their original version.</p></div>
@if($template['id']!=='new')
<a class="button" target="_blank" rel="noopener" href="{{ url('admin/agreements/templates/'.$template['id'].'/preview') }}">Preview saved PDF ↗</a>
@endif
</div><div class="nda-editor-layout"><form method="post" class="panel" data-nda-editor action="{{ url('admin/agreements/templates/'.$template['id']) }}">
@csrf
<div class="form-grid"><label>Template name<input name="name" required maxlength="120" value="{{ old('name',$template['name']) }}"></label><label>Version<input name="version" required maxlength="80" value="{{ old('version',$template['version']) }}"></label></div><label>Document title<input name="title" data-nda-title required maxlength="180" value="{{ old('title',$template['title']) }}"></label><div class="nda-token-list"><small>Insert applicant information</small>
@foreach(['contractor_name','contractor_email','reference','effective_date'] as $token)
<button type="button" data-nda-token="{{ $token }}">{{ '{'.'{'.$token.'}'.'}' }}</button>
@endforeach
</div><label>Agreement clauses<textarea name="body" data-nda-body required maxlength="50000" rows="20">{{ old('body',$template['body']) }}</textarea></label><label>Letterhead footer<input name="footer" data-nda-footer required maxlength="140" value="{{ old('footer',$template['footer']) }}"></label><button class="primary">Save template</button></form><div class="nda-editor-preview"><div class="eyebrow">LIVE DOCUMENT PREVIEW · EXAMPLE APPLICANT</div><article class="nda-letterhead"><header><img src="{{ asset('branding/linguals-logo.png') }}" alt="The Linguals"><span>CONFIDENTIAL</span></header><h2 data-nda-preview-title></h2><div class="nda-clause-body" data-nda-preview-body></div><footer data-nda-preview-footer></footer></article></div></div><script defer src="{{ asset('agreement-editor.js') }}"></script>
@endsection
