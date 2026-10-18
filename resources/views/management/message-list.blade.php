@extends('layout')
@section('content')
<h1>Applicant conversations</h1><div class="panel">@foreach($applications as $application)<div class="service"><a href="{{ url('admin/messages/'.$application->id) }}">{{ $application->name }}</a><small>{{ $application->email }}</small></div>@endforeach{{ $applications->links() }}</div>
@endsection
