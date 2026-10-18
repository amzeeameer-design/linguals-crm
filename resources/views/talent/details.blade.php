@extends('talent.layout')
@section('content')
@if($dashboardMode??false)<a class="lab-back" href="{{ url('portal').'#/user/services' }}">← Back to your dashboard</a>@endif<div class="service-intro"><div class="lab-kicker">STAGE TWO · YOUR PROFESSIONAL EXPERTISE</div><h1>Let’s get to know<br><em>your work.</em></h1><p class="lab-sub">Hi {{ $application->name }}. Complete the services you offer. Each section is short, and you can go back before submitting.</p></div>
@if(!empty($application->profile['service_stage_completed_at']))<div class="stage-later-note">Your details were submitted previously. Updating this form sends them back for review.</div>
@endif
<form method="post" enctype="multipart/form-data" action="{{ request()->fullUrl() }}" id="service-stage-form" data-error-fields="{{ json_encode($errors->keys()) }}">
@csrf
<section class="detail-step"><div class="lab-kicker">YOUR SERVICES</div><h2>What do you offer?</h2><p class="lab-sub">Select the services you can confidently deliver. Each one has its own short form.</p><div class="lab-picks">
@foreach($services as $service=>$questions)
@php
$key=Illuminate\Support\Str::slug($service);
$selected=in_array($service,old('services',$application->services->pluck('service')->all()));
@endphp<button type="button" class="lab-pick service-tile {{ $selected?'selected':'' }}" data-detail-service="{{ $key }}" data-value="{{ $service }}" aria-pressed="{{ $selected?'true':'false' }}"><b>{{ $service }}</b><span class="tile-check" aria-hidden="true">{{ $selected?'✓':'+' }}</span></button>
@endforeach</div><div id="detail-selection-inputs"></div><p class="stage-error" data-service-error role="alert"></p></section>
@foreach($services as $service=>$questions)
@php
$key=Illuminate\Support\Str::slug($service);
$record=$application->services->firstWhere('service',$service);
$primaryPair=$primaryPairs[$record?->id]??null;
$savedRate=$serviceRates[$record?->id]??null;
$schema=config('service_forms.'.$service,[]);
$languageFields=$schema['language_fields']??[];
@endphp
<div class="service-form-container" data-detail-panel="{{ $key }}" data-domain-values="{{ json_encode(old('domains.'.$key,$serviceDomains[$record?->id]??[])) }}" hidden>
<section class="detail-step" data-service-screen><div class="lab-kicker">{{ strtoupper($service) }} · 1 / 4</div><h2>Your working languages</h2><p class="lab-sub">Tell us where your language strengths lie.</p><div class="lab-grid">
@foreach($languageFields as $field)
@include('talent.service-question',['field'=>$field,'questionLabel'=>$questions[$field]])
@endforeach<label class="lab-field"><span>Main working language *</span><input class="lab-input" name="details[{{ $key }}][language]" list="service-language-options" maxlength="80" required value="{{ old("details.$key.language",$record?->details['language']??$primaryLanguage?->language??'') }}"></label><label class="lab-field"><span>Proficiency *</span><select class="lab-input" name="details[{{ $key }}][proficiency]" required><option value="">Select your level</option>
@foreach(['A1','A2','B1','B2','C1','C2','Native'] as $level)<option 
@selected(old("details.$key.proficiency",$record?->details['proficiency']??$primaryLanguage?->proficiency??'')===$level)>{{ $level }}</option>
@endforeach</select></label></div>
@if(in_array($service,['Translation','Interpretation','Localization','Back Translation','Subtitling','Transcreation','LQA']))<div class="stage-pair-box"><h3>Additional language pairs</h3><p>Add other source and target directions you deliver professionally.</p><div data-extra-pairs="{{ $key }}">
@foreach(old('pairs.'.$key,$servicePairs[$record?->id]??[]) as $i=>$pair)<div class="stage-extra-pair"><label class="lab-field"><span>Source</span><input class="lab-input" list="service-language-options" name="pairs[{{ $key }}][{{ $i }}][source]" maxlength="80" value="{{ $pair['source'] }}" required></label><label class="lab-field"><span>Target</span><input class="lab-input" list="service-language-options" name="pairs[{{ $key }}][{{ $i }}][target]" maxlength="80" value="{{ $pair['target'] }}" required></label><button type="button" class="lab-back" data-remove-pair aria-label="Remove language pair">×</button></div>
@endforeach</div><button type="button" class="lab-back" data-add-pair="{{ $key }}">+ Add another pair</button></div>
@endif
</section>
<section class="detail-step" data-service-screen><div class="lab-kicker">{{ strtoupper($service) }} · 2 / 4</div><h2>Your experience & expertise</h2><p class="lab-sub">Help us understand the work you have done.</p>
@foreach($questions as $field=>$questionLabel)
@if(!in_array($field,$languageFields)&&$field!=='language')
@include('talent.service-question')
@endif 
@endforeach<label class="lab-field"><span>Years of professional experience *</span><input class="lab-input" type="number" name="details[{{ $key }}][experience]" min="0" max="80" required value="{{ old("details.$key.experience",$record?->experience_years??'') }}"></label><h3>Domain expertise</h3><p class="lab-sub">Select the domains you work in. You can select more than one.</p><div class="domain-tiles">
@foreach($domains as $domain)<button type="button" class="domain-tile" data-detail-domain="{{ $domain }}" aria-pressed="false">{{ $domain }}</button>
@endforeach</div><div data-domain-inputs="{{ $key }}"></div></section>
<section class="detail-step" data-service-screen><div class="lab-kicker">{{ strtoupper($service) }} · 3 / 4</div><h2>Your setup & rates</h2><p class="lab-sub">Optional for now. These details help us prepare a suitable project brief.</p><div class="lab-grid">
@foreach($schema['setup']??[] as $field=>$definition)<label class="lab-field"><span>{{ $definition['label'] }}</span>
@if(isset($definition['options']))<select class="lab-input" name="details[{{ $key }}][{{ $field }}]"><option value="">Select an option</option>
@foreach($definition['options'] as $option)<option 
@selected(old("details.$key.$field",$record?->details[$field]??'')===$option)>{{ $option }}</option>
@endforeach</select>
@elseif(($definition['type']??'')==='textarea')<textarea class="lab-input" name="details[{{ $key }}][{{ $field }}]" rows="3" maxlength="2000">{{ old("details.$key.$field",$record?->details[$field]??'') }}</textarea>
@else<input class="lab-input" name="details[{{ $key }}][{{ $field }}]" maxlength="500" placeholder="{{ $definition['placeholder']??'' }}" value="{{ old("details.$key.$field",$record?->details[$field]??'') }}">
@endif</label>
@endforeach</div><div class="stage-rate-grid"><label class="lab-field"><span>Rate amount</span><input class="lab-input" type="number" step="0.0001" min="0.0001" max="1000000" name="details[{{ $key }}][rate_amount]" value="{{ old("details.$key.rate_amount",$record?->details['rate_amount']??$savedRate?->amount??'') }}" placeholder="0.08"></label><label class="lab-field"><span>Currency</span><select class="lab-input" name="details[{{ $key }}][rate_currency]"><option value="">Choose</option>
@foreach(['USD','EUR','GBP','PKR','AED','SAR','CAD','AUD'] as $currency)<option 
@selected(old("details.$key.rate_currency",$record?->details['rate_currency']??$savedRate?->currency??'')===$currency)>{{ $currency }}</option>
@endforeach</select></label><label class="lab-field"><span>Per</span><select class="lab-input" name="details[{{ $key }}][rate_unit]"><option value="">Choose</option>
@foreach($schema['rate_units']??['hour','word','project'] as $unit)<option 
@selected(old("details.$key.rate_unit",$record?->details['rate_unit']??$savedRate?->unit??'')===$unit)>{{ $unit }}</option>
@endforeach</select></label></div><label class="lab-field"><span>Rate notes</span><input class="lab-input" name="details[{{ $key }}][rate]" maxlength="100" value="{{ old("details.$key.rate",$record?->details['rate']??'') }}" placeholder="Minimum fee, usage rights or other conditions"></label></section>
<section class="detail-step" data-service-screen><div class="lab-kicker">{{ strtoupper($service) }} · 4 / 4</div><h2>Samples & availability</h2><p class="lab-sub">{{ $schema['sample_help']??'Share an authorized example of your work if available.' }}</p><label class="lab-field"><span>{{ $schema['sample_label']??'Professional sample or portfolio link' }}</span><input class="lab-input" type="url" name="details[{{ $key }}][portfolio_url]" maxlength="2048" value="{{ old("details.$key.portfolio_url",$record?->details['portfolio_url']??'') }}" placeholder="https://"></label><label class="lab-field"><span>Upload a sample document (optional)</span><input class="lab-input" type="file" name="service_samples[{{ $key }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"><small>PDF, Word or image · up to 5 MB. Upload only material you are allowed to share. Voice demos can use the link above.</small></label>@if(!empty($record?->details['sample_document_id']))<p class="lab-sub">Your previous sample is retained unless you upload a replacement.</p>@endif<div class="lab-grid"><label class="lab-field"><span>Availability</span><select class="lab-input" name="details[{{ $key }}][availability]"><option value="">Choose</option>
@foreach(['Available now','Part-time','By arrangement','Currently unavailable'] as $availability)<option 
@selected(old("details.$key.availability",$record?->details['availability']??'')===$availability)>{{ $availability }}</option>
@endforeach</select></label><label class="lab-field"><span>Typical turnaround or booking notice</span><input class="lab-input" name="details[{{ $key }}][turnaround]" maxlength="200" value="{{ old("details.$key.turnaround",$record?->details['turnaround']??'') }}" placeholder="e.g. 2 working days"></label></div><label class="lab-field"><span>Anything else we should know?</span><textarea class="lab-input" name="details[{{ $key }}][notes]" rows="3" maxlength="2000">{{ old("details.$key.notes",$record?->details['notes']??'') }}</textarea></label></section>
</div>
@endforeach
<section class="detail-step"><div class="lab-kicker">REVIEW & SUBMIT</div><h2>Your expertise is ready for review</h2><p class="lab-sub">Check your service summary below. Use Previous to update any section. Our team reviews your details before sending an onboarding invitation.</p><div data-service-review class="service-review-summary"></div><label class="lab-consent"><input type="checkbox" name="consent" value="1" required 
@checked(old('consent'))><span>I confirm my service details are accurate and agree to their review for onboarding.</span></label><button class="lab-next" type="submit">Submit service details →</button></section>
<datalist id="service-language-options">
@foreach(['English','Urdu','Arabic','French','Spanish','German','Chinese','Hindi','Portuguese','Japanese','Russian','Italian','Turkish','Korean','Bengali','Persian','Pashto','Punjabi','Indonesian','Vietnamese','Swahili'] as $language)<option value="{{ $language }}">
@endforeach</datalist></form>
<noscript>Please enable JavaScript to use this guided service form.</noscript><script defer src="{{ asset('service-stage.js') }}"></script>
@endsection
