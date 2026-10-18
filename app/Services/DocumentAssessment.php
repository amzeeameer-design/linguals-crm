<?php

namespace App\Services;

use App\Models\TalentApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentAssessment
{
    /** @param array<string, mixed> $document @return array<string, mixed> */
    public static function run(array $document, TalentApplication $application): array
    {
        $findings = [];
        $text = ['text' => '', 'coverage' => 'Original file unavailable'];
        $add = static function (string $field, string $result, string $detail) use (&$findings): void {
            $findings[] = compact('field', 'result', 'detail');
        };
        $intact = false;
        try {
            $bytes = PrivateFiles::bytes($document);
            $intact = PrivateFiles::verify($document, $bytes);
            $add('Storage integrity', $intact ? 'found' : 'review', $intact ? 'Original bytes match the recorded SHA-256 at upload. This checks storage integrity, not authenticity.' : 'The stored bytes do not match the upload hash. Stop review and investigate the file.');
            if ($intact) {
                $text = DocumentText::read($bytes, $document['mime'], $document['original_name']);
                $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                $known = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
                if (in_array($document['mime'], $known, true)) {
                    $add('File format', $detected === $document['mime'] ? 'found' : 'review', $detected === $document['mime'] ? 'Content format matches the stored MIME type.' : 'File signature and stored format differ. Review the original.');
                }
            }
        } catch (\Throwable $error) {
            report($error);
            $add('Storage integrity', 'review', 'The original could not be read. Check private storage and restore from backup before deciding.');
        }
        $duplicateApplications = DB::table('documents')->where('sha256', $document['sha256'])->where('application_id', '!=', $application->id)->distinct()->count('application_id');
        if ($duplicateApplications > 0) {
            $add('Duplicate file', 'review', 'Identical bytes appear in '.$duplicateApplications.' other application(s). Shared templates can also produce this flag; it is not a fraud finding.');
        }
        $readable = $intact && mb_strlen(trim($text['text'])) >= 30;
        $add('Text coverage', $readable ? 'found' : 'not_checked', $text['coverage']);
        $values = ['name' => $application->name, 'email' => $application->email];
        if ($document['category'] === 'CV') {
            $values['country'] = $application->country;
        }
        foreach ($values as $field => $value) {
            $found = $readable && filled($value) && self::contains($text['text'], (string) $value);
            $result = ! $readable || blank($value) ? 'not_checked' : ($found ? 'found' : 'not_found');
            $add($field, $result, $result === 'found' ? 'Submitted value appears in readable text; this does not establish authenticity.' : ($result === 'not_found' ? 'Submitted value was not found. Abbreviations, document purpose and language may explain this; review manually.' : 'No reliable comparison available. Review the original.'));
        }
        if ($readable && $document['category'] === 'CV') {
            preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text['text'], $matches);
            $emails = array_unique(array_map('strtolower', $matches[0]));
            if ($emails && ! in_array(strtolower($application->email), $emails, true)) {
                $add('Email discrepancy', 'review', 'Readable CV contains an email address but not the submitted address. Ask the applicant to clarify.');
            }
            foreach (DB::table('application_languages')->where('application_id', $application->id)->pluck('language') as $language) {
                $add('Language: '.$language, self::contains($text['text'], $language) ? 'found' : 'not_found', 'Text mention only. Proficiency and professional capability require separate review.');
            }
            if (preg_match('/\b(?:19|20)\d{2}\b/', $text['text'])) {
                preg_match_all('/\b(?:19|20)\d{2}\b/', $text['text'], $years);
                if (collect($years[0])->contains(fn (string $year): bool => (int) $year > (int) now()->format('Y') + 1)) {
                    $add('Future dates', 'review', 'A year beyond next year appears in the CV. It may be a planned qualification; verify its context.');
                }
            }
        }
        $add('Originality assessment', 'not_checked', 'Issuer authenticity, signatures, image manipulation and credentials cannot be established by text or file hashes. A reviewer must verify evidence; no external service is contacted.');

        return [
            'status' => 'completed', 'provider' => 'internal_consistency', 'model_version' => 'rules-v2',
            'outcome' => 'manual_review_required', 'findings' => $findings,
            'coverage' => ['text' => $text['coverage'], 'authenticity' => 'Not verified', 'external_services' => false, 'integrity' => $intact, 'readable_characters' => mb_strlen($text['text']), 'flags' => collect($findings)->where('result', 'review')->count()],
        ];
    }

    private static function contains(string $text, string $value): bool
    {
        $normalize = static fn (string $input): string => trim(preg_replace('/[^\p{L}\p{N}@.]+/u', ' ', mb_strtolower($input)) ?? '');

        return str_contains($normalize($text), $normalize($value));
    }
}
