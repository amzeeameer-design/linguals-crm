<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivateFiles
{
    /** @param array<string, mixed> $file */
    public static function register(int $applicationId, string $category, array $file): int
    {
        $previous = DB::table('documents')->where('application_id', $applicationId)->where('category', $category)->orderByDesc('version')->first();

        $id = DB::table('documents')->insertGetId([
            'application_id' => $applicationId, 'category' => $category, 'original_name' => $file['name'],
            'disk' => 'local', 'path' => $file['path'], 'mime' => $file['mime'], 'bytes' => $file['bytes'],
            'original_bytes' => $file['original_bytes'], 'encoding' => $file['encoding'], 'sha256' => $file['sha256'],
            'version' => ($previous->version ?? 0) + 1, 'supersedes_id' => $previous->id ?? null,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Queue::connection('database')->push(new \App\Jobs\AssessDocument($id), '', 'documents');

        return $id;
    }
    /** @param array<string, mixed> $file */
    public static function verify(array $file, string $bytes): bool
    {
        return ! empty($file['sha256']) && hash_equals($file['sha256'], hash('sha256', $bytes));
    }

    public static function photo(UploadedFile $file): string
    {
        $info = getimagesize($file->getRealPath());
        if (! $info || $info[0] * $info[1] > 20000000) {
            throw ValidationException::withMessages(['profile_image' => 'Choose an image smaller than 20 megapixels.']);
        }
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['profile_image' => 'Photo processing is unavailable. Please contact the talent team.']);
        }
        $source = imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['profile_image' => 'This image could not be read.']);
        }
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getRealPath());
            $orientation = (int) ($exif['Orientation'] ?? 1);
            $angle = match ($orientation) {3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0};
            if ($angle !== 0) {
                $rotated = imagerotate($source, $angle, 0);
                if ($rotated !== false) {
                    imagedestroy($source);
                    $source = $rotated;
                }
            }
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($source, IMG_FLIP_VERTICAL);
            }
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $ratio = min(1, 1600 / $sourceWidth, 1600 / $sourceHeight);
        $image = imagecreatetruecolor(max(1, (int) round($sourceWidth * $ratio)), max(1, (int) round($sourceHeight * $ratio)));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, imagesx($image), imagesy($image), $sourceWidth, $sourceHeight);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($source);
        imagedestroy($image);
        $path = 'profile-photos/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    public static function store(UploadedFile $file, string $directory = 'application-cvs'): array
    {
        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $info = @getimagesize($file->getRealPath());
            if (! $info || $info[0] * $info[1] > 20000000) {
                throw ValidationException::withMessages(['document' => 'Choose a readable image smaller than 20 megapixels.']);
            }
        }
        $raw = file_get_contents($file->getRealPath());
        $encoded = $raw;
        $encoding = null;
        if (strlen($raw) > 2 * 1024 * 1024) {
            $compressed = gzencode($raw, 6);
            if ($compressed !== false && strlen($compressed) < strlen($raw) * .95) {
                $encoded = $compressed;
                $encoding = 'gzip';
            }
        }
        $path = $directory.'/'.Str::uuid().($encoding ? '.gz' : '.'.$file->extension());
        Storage::disk('local')->put($path, $encoded);

        return ['path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'bytes' => strlen($encoded), 'original_bytes' => strlen($raw), 'encoding' => $encoding, 'sha256' => hash('sha256', $raw)];
    }

    public static function bytes(array $file): string
    {
        $bytes = Storage::disk('local')->get($file['path']);
        if (($file['encoding'] ?? null) === 'gzip') {
            $bytes = @gzdecode($bytes, 25 * 1024 * 1024);
            if ($bytes === false) {
                throw new \RuntimeException('The stored document could not be restored.');
            }
        }

        return $bytes;
    }
}
