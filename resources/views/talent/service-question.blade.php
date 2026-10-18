<label class="lab-field"><span>{{ $questionLabel }} *</span>
@if($field==='mode')<select class="lab-input" name="details[{{ $key }}][{{ $field }}]" required><option value="">Select your interpreting mode</option>
@foreach(['Consecutive','Simultaneous','Consecutive and simultaneous','Whispered','Liaison'] as $mode)<option 
@selected(old("details.$key.$field",$record?->details[$field]??($field==='source'?$primaryPair?->source_language:($field==='target'?$primaryPair?->target_language:($field==='languages'?$primaryLanguage?->language:''))))===$mode)>{{ $mode }}</option>
@endforeach</select>
@elseif(in_array($field,['source','target','languages']))<input class="lab-input" name="details[{{ $key }}][{{ $field }}]" maxlength="{{ in_array($field,['source','target'])?80:2000 }}" value="{{ old("details.$key.$field",$record?->details[$field]??($field==='source'?$primaryPair?->source_language:($field==='target'?$primaryPair?->target_language:($field==='languages'?$primaryLanguage?->language:'')))) }}" 
@if(in_array($field,['source','target'])) list="service-language-options" 
@endif required>
@else<textarea class="lab-input" name="details[{{ $key }}][{{ $field }}]" rows="3" maxlength="2000" required>{{ old("details.$key.$field",$record?->details[$field]??($field==='source'?$primaryPair?->source_language:($field==='target'?$primaryPair?->target_language:($field==='languages'?$primaryLanguage?->language:'')))) }}</textarea>
@endif</label>
