@extends('layout')
@section('content')
<div class="modern-management" data-management-tabs data-initial-tab="{{ $isAdmin && ($application || $errors->any()) ? 'compose' : 'sent' }}">
<div class="heading"><div><div class="eyebrow">APPLICANT UPDATES</div><h1>Notifications</h1><p>{{ $isAdmin?'Send a clear update or request acknowledgement from an applicant.':'Updates and next steps from The Linguals team.' }}</p></div>
@if($application && $isAdmin)<a class="button" href="{{ url('workspace').'#/admin/review/'.$application->id }}">View applicant profile →</a>
@endif</div>
<div class="modern-tabs" role="tablist" aria-label="Notification sections"><button role="tab" type="button" id="sent-tab" data-tab="sent" aria-controls="sent-panel" aria-selected="true">{{ $isAdmin?'Sent notifications':'Your updates' }} <span>{{ $notices->total() }}</span></button>@if($isAdmin)<button role="tab" type="button" id="compose-tab" data-tab="compose" aria-controls="compose-panel" aria-selected="false" tabindex="-1">+ New notification</button>@endif</div><section id="sent-panel" role="tabpanel" aria-labelledby="sent-tab" data-tab-panel="sent"><section class="panel notification-feed"><div class="notification-heading"><h2>{{ $isAdmin?'Sent notifications':'Your notifications' }}</h2><span class="badge">{{ $notices->total() }}</span></div><form method="get" class="notification-search"><label for="notification-search">{{ $isAdmin?'Search applicant or notification':'Search notifications' }}</label><div><input id="notification-search" name="q" value="{{ $search }}" placeholder="Search…"><button type="submit">Search</button>
@if($search)<a class="button" href="{{ request()->url() }}">Clear</a>
@endif</div></form>
@forelse($notices as $notice)<details class="notification-item modern-notice"><summary><span class="modern-notice-icon" aria-hidden="true">↗</span><span class="modern-notice-label"><div class="notification-item-header"><h3>{{ $notice->title ?: 'Update from The Linguals' }}</h3><span class="badge">{{ $notice->acknowledged_at?'Acknowledged':($notice->read_at?'Read':'Unread') }}</span></div><small>{{ $isAdmin?$notice->recipient_name.' · ':'' }}{{ Illuminate\Support\Carbon::parse($notice->created_at)->format('d M Y · H:i') }}</small><span class="modern-notice-preview">{{ Illuminate\Support\Str::limit($notice->body,100) }}</span></span><span class="modern-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span></summary><div class="modern-notice-body"><p>{{ $notice->body }}</p>
@if($notice->requires_acknowledgement)<small class="acknowledgement-note">{{ $notice->acknowledged_at?'Acknowledged '.Illuminate\Support\Carbon::parse($notice->acknowledged_at)->format('d M Y, H:i'):'Acknowledgement requested' }}</small>
@endif
@if(!$isAdmin && (!$notice->read_at || ($notice->requires_acknowledgement && !$notice->acknowledged_at)))<form method="post" action="{{ url('portal/notifications/'.$notice->id.'/status') }}">
@csrf
@if(!$notice->read_at)<button name="action" value="read">Mark as read</button>
@endif 
@if($notice->requires_acknowledgement && !$notice->acknowledged_at)<button class="primary" name="action" value="acknowledge">Acknowledge</button>
@endif</form>
@endif</div></details>
@empty<div class="notification-empty"><h3>{{ $search?'No matching notifications':'No notifications yet' }}</h3><p>{{ $isAdmin?'Choose New notification to send an applicant an update.':'Your team’s updates will appear here.' }}</p></div>
@endforelse
<div class="notification-pagination">
@if($notices->previousPageUrl())<a href="{{ $notices->previousPageUrl() }}">← Previous</a>
@endif<span>{{ $notices->currentPage() }} / {{ $notices->lastPage() }}</span>
@if($notices->nextPageUrl())<a href="{{ $notices->nextPageUrl() }}">Next →</a>
@endif</div></section>

</section>@if($isAdmin)<section id="compose-panel" role="tabpanel" aria-labelledby="compose-tab" data-tab-panel="compose" hidden><section class="panel notification-compose"><h2>Send a notification</h2><p>This appears in the applicant portal. Email delivery is managed separately.</p><form method="get" action="{{ request()->url() }}#compose" class="modern-recipient-search"><label>Find recipient<input name="q" value="{{ $search }}" placeholder="Applicant name or email"></label><button>Find</button></form><form method="post" data-notification-compose action="{{ url('admin/notifications') }}">
@csrf<label>Recipient<select name="application_id" required><option value="">Select an applicant</option>
@if($application)<option value="{{ $application->id }}" selected>{{ $application->name }} · {{ $application->email }}</option>
@endif 
@foreach($recipients as $recipient)
@if($recipient->id!==$application?->id)<option value="{{ $recipient->id }}" 
@selected(old('application_id')==$recipient->id)>{{ $recipient->name }} · {{ $recipient->email }}</option>
@endif 
@endforeach</select><small>Find a recipient above. Showing up to 100 matches.</small></label><label>Subject<input name="title" required maxlength="180" value="{{ old('title') }}" placeholder="e.g. Please update your service details"></label><label>Notification<textarea name="body" rows="5" required maxlength="5000" placeholder="Explain the update and any action needed…">{{ old('body') }}</textarea></label><label class="notification-check"><input type="checkbox" name="requires_acknowledgement" value="1" 
@checked(old('requires_acknowledgement'))><span>Ask the applicant to acknowledge this notice</span></label><button class="primary" type="submit">Send notification →</button></form></section></section>
@endif</div>
<link rel="stylesheet" href="{{ asset('management-modern.css') }}?v={{ filemtime(public_path('management-modern.css')) }}"><script src="{{ asset('management-modern.js') }}?v={{ filemtime(public_path('management-modern.js')) }}" defer></script>
@endsection
