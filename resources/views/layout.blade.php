<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>The Linguals · Talent workspace</title><link rel="icon" type="image/png" href="{{ asset('branding/linguals-favicon.png') }}"><link rel="stylesheet" href="{{ asset('portal.css') }}"><link rel="stylesheet" href="{{ asset('management-shell.css') }}?v={{ filemtime(public_path('management-shell.css')) }}"><link rel="manifest" href="{{ asset('manifest.webmanifest') }}"><meta name="theme-color" content="#176b59"><meta name="apple-mobile-web-app-capable" content="yes"><link rel="apple-touch-icon" href="{{ asset('branding/app-icon-192.png') }}"><link rel="stylesheet" href="{{ asset('portal-install.css') }}"><link rel="stylesheet" href="{{ asset('content-refresh.css') }}?v={{ filemtime(public_path('content-refresh.css')) }}"><link rel="stylesheet" href="{{ asset('workspace-ui.css') }}?v={{ filemtime(public_path('workspace-ui.css')) }}"></head>
<body class="management-workspace">
@php
$admin=auth()->user()?->role==='admin';
$applicationId=isset($application)?$application->id:null;
$navigation=$admin?[
['Talent discovery','workspace#/admin/talent','search',false],
['Overview','workspace#/admin/dashboard','grid',false],
['Applications','workspace#/admin/applications','file',request()->is('admin/applications*')||request()->is('admin')],
['KYC verification','workspace#/admin/verification','shield',false],
['Invitations','workspace#/admin/invitations','mail',false],
['Agreements','admin/agreements','pen',request()->is('admin/agreements*')],
['Notifications','admin/notifications','mail',request()->is('admin/notifications*')],
['Reports & insights','workspace#/admin/reports','chart',false],
['Settings','admin/settings','settings',request()->is('admin/settings*')]
]:[
['My dashboard','portal#/user/dashboard','grid',false],
['My profile','portal#/user/profile','users',false],
['Services','portal#/user/services','briefcase',false],
['Documents','portal#/user/documents','shield',request()->is('documents*')],
['Agreements','portal/agreements','pen',request()->is('portal/agreements*')],
['Notifications','portal/notifications','mail',request()->is('portal/notifications*')],
['Settings','portal#/user/settings','settings',false]
];
$pageName=request()->is('admin/readiness')?'Launch readiness':(request()->is('*files')?'File library':(request()->is('admin/settings*')?'Settings':(request()->is('*notifications*')?'Notifications':(request()->is('*agreements*')?'Agreements':(request()->is('documents*')?'Document review':(request()->is('admin/applications/*/edit')?'Edit applicant details':'Applications'))))));
@endphp
<aside class="management-sidebar"><a class="management-brand" href="{{ url($admin?'workspace#/admin/talent':'portal') }}"><img src="{{ asset('branding/linguals-logo.png') }}" alt="The Linguals"></a><span class="management-nav-label">WORKSPACE</span><nav aria-label="{{ $admin?'Admin':'Applicant' }} navigation">@foreach($navigation as [$name,$path,$symbol,$active])@if($name==='Reports & insights'||(!$admin&&$name==='Settings'))<span class="management-nav-label">MANAGEMENT</span>@endif<a class="management-nav {{ $active?'active':'' }}" href="{{ url($path) }}" @if($active) aria-current="page" @endif><svg viewBox="0 0 24 24" aria-hidden="true"><use href="{{ asset('workspace-icons.svg') }}#{{ $symbol }}"></use></svg>{{ $name }}</a>@endforeach</nav><div class="management-account"><span class="management-avatar">{{ strtoupper(collect(explode(' ',auth()->user()?->name ?? 'TL'))->take(2)->map(fn($part)=>substr($part,0,1))->implode('')) }}</span><div><b>{{ auth()->user()?->name }}</b><small>{{ $admin?'Workspace administrator':'Talent account' }}</small></div><form action="{{ url('logout') }}" method="post">@csrf<button aria-label="Sign out" title="Sign out"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="{{ asset('workspace-icons.svg') }}#logout"></use></svg></button></form></div></aside>
<main class="management-main"><header class="management-topbar"><div><a href="{{ url($admin?'workspace':'portal') }}">Talent workspace</a><span>/</span><span>{{ $pageName }}</span></div><span class="management-avatar">{{ strtoupper(collect(explode(' ',auth()->user()?->name ?? 'TL'))->take(2)->map(fn($part)=>substr($part,0,1))->implode('')) }}</span></header><div class="management-content">
@if($applicationId)<nav class="applicant-navigation" aria-label="Applicant navigation"><a href="{{ url('workspace').'#/admin/review/'.$applicationId }}">Applicant profile</a><a href="{{ url('admin/applications/'.$applicationId.'/edit') }}" @if(request()->is('*/edit')) aria-current="page" @endif>Edit details</a><a href="{{ url('admin/notifications/'.$applicationId) }}">Notifications</a></nav>@endif
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@yield('content')
</div></main><script src="{{ asset('portal-install.js') }}" defer></script></body></html>
