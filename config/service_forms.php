<?php

return [
    'Translation' => [
        'language_fields' => ['source', 'target'],
        'expertise_fields' => ['speciality'],
        'setup' => [
            'cat_tools' => ['label' => 'CAT tools and terminology tools', 'placeholder' => 'Trados, memoQ, Phrase, Wordfast…'],
            'quality_process' => ['label' => 'Your quality assurance process', 'type' => 'textarea'],
        ],
        'rate_units' => ['word', 'hour', 'page', 'project'],
        'sample_label' => 'Translation portfolio or sample link',
        'sample_help' => 'Share a sample you have permission to disclose. Remove confidential client information.',
    ],
    'Interpretation' => [
        'language_fields' => ['languages', 'mode'],
        'expertise_fields' => ['setting'],
        'setup' => [
            'delivery' => ['label' => 'Delivery format', 'options' => ['Remote', 'On-site', 'Remote and on-site']],
            'equipment' => ['label' => 'Remote equipment and platforms', 'placeholder' => 'Headset, connection, Zoom, Teams, interpreting platforms…'],
            'travel' => ['label' => 'Travel availability and locations', 'placeholder' => 'Cities, countries or regions you can cover'],
        ],
        'rate_units' => ['hour', 'half-day', 'day', 'project'],
        'sample_label' => 'Interpreting sample or professional portfolio',
        'sample_help' => 'Share a public sample or an authorized demonstration. Do not upload confidential recordings.',
    ],
    'Voice Over' => [
        'language_fields' => ['languages', 'voice'],
        'expertise_fields' => ['studio'],
        'setup' => [
            'microphone' => ['label' => 'Microphone and recording equipment'],
            'recording_software' => ['label' => 'Recording and editing software'],
            'audio_formats' => ['label' => 'Audio formats you deliver', 'placeholder' => 'WAV, MP3, sample rate and bit depth'],
            'live_direction' => ['label' => 'Live-directed sessions', 'options' => ['Available', 'Not available', 'By arrangement']],
        ],
        'rate_units' => ['word', 'finished-minute', 'hour', 'project'],
        'sample_label' => 'Voice demo reel link',
        'sample_help' => 'A public or accessible demo link helps us review your accents, delivery and audio quality.',
    ],
];
