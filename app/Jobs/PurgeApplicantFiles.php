<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class PurgeApplicantFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @param list<string> $paths */
    public function __construct(public array $paths)
    {
        $this->onQueue('documents');
    }

    public function handle(): void
    {
        foreach ($this->paths as $path) {
            if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, ':')) {
                throw new \RuntimeException('Invalid private file cleanup path.');
            }
            if (Storage::disk('local')->exists($path) && ! Storage::disk('local')->delete($path)) {
                throw new \RuntimeException('Private file cleanup failed; retry the background job.');
            }
        }
    }
}
