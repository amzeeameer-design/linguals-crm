<?php

namespace App\Jobs;

use App\Models\TalentApplication;
use App\Services\DocumentAssessment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class AssessDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 45;

    public function __construct(public int $documentId)
    {
        $this->onQueue('documents');
    }

    public function handle(): void
    {
        $document = DB::table('documents')->find($this->documentId);
        if (! $document) {
            return;
        }
        $application = TalentApplication::findOrFail($document->application_id);
        $assessment = DocumentAssessment::run((array) $document, $application);
        DB::table('document_assessments')->insert([
            'document_id' => $this->documentId, 'status' => $assessment['status'], 'provider' => $assessment['provider'],
            'model_version' => $assessment['model_version'], 'outcome' => $assessment['outcome'],
            'findings' => json_encode($assessment['findings']), 'coverage' => json_encode($assessment['coverage']),
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        if (! DB::table('documents')->where('id', $this->documentId)->exists()) {
            return;
        }
        DB::table('document_assessments')->insert([
            'document_id' => $this->documentId, 'status' => 'failed', 'provider' => 'internal_consistency',
            'model_version' => 'rules-v2', 'outcome' => 'manual_review_required',
            'findings' => json_encode([['field' => 'Automatic assessment', 'result' => 'review', 'detail' => 'The automatic check failed. Run assessment again or review the original manually. Failure is never an authenticity pass.']]),
            'coverage' => json_encode(['authenticity' => 'Not verified', 'external_services' => false]),
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
