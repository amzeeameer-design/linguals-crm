@extends('layout')
@section('content')
<div class="heading"><div><div class="eyebrow">APPLICANT DATA</div><h1>Delete applicant</h1><p>Verify the record before permanently removing it.</p></div><a class="button" href="{{ url('workspace').'#/admin/review/'.$application->id }}">Cancel & return</a></div>
<section class="panel applicant-delete-panel">
<h2>{{ $application->name }}</h2><p>{{ $application->email }}</p>
<div class="notice" role="note">This permanently removes the application, service details, documents, agreements, notifications and linked applicant login. Private files are purged by the background worker. This action has no restore option.</div>
<form action="{{ url('admin/applications/'.$application->id.'/delete') }}" method="post">
@csrf
<label>Type the complete applicant reference<strong class="delete-reference">{{ $application->reference }}</strong><input name="reference" required autocomplete="off" value="{{ old('reference') }}"></label>
<label>Your administrator password<input type="password" name="password" required autocomplete="current-password"></label>
<label class="delete-confirmation"><input type="checkbox" name="confirm_deletion" value="1" required> I understand that this applicant and their data will be permanently removed.</label>
<button class="primary delete-applicant-submit" type="submit">Permanently delete applicant</button>
</form></section>
@endsection
