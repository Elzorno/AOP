<?php

namespace App\Services;

use App\Support\SafeCsv;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class PublicationArtifactDownloadService
{
    public function download(string $storagePath, string $downloadName): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($storagePath), 404);

        return response()->streamDownload(function () use ($storagePath, $downloadName) {
            if (str_ends_with(strtolower($downloadName), '.csv')) {
                $this->streamCsv($storagePath);

                return;
            }

            if (str_ends_with(strtolower($downloadName), '.zip')) {
                $this->streamSanitizedZip($storagePath);

                return;
            }

            $this->streamOriginal($storagePath);
        }, $downloadName);
    }

    private function streamCsv(string $storagePath): void
    {
        $input = Storage::disk('local')->readStream($storagePath);
        if ($input === false) {
            return;
        }
        SafeCsv::sanitizeStream($input, fopen('php://output', 'w'));
        fclose($input);
    }

    private function streamSanitizedZip(string $storagePath): void
    {
        $source = new ZipArchive;
        if ($source->open(Storage::disk('local')->path($storagePath)) !== true) {
            return;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'aop-publication-');
        if ($temporaryPath === false) {
            $source->close();

            return;
        }

        $target = new ZipArchive;
        if ($target->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $source->close();
            @unlink($temporaryPath);

            return;
        }

        for ($index = 0; $index < $source->numFiles; $index++) {
            $name = $source->getNameIndex($index);
            if (! is_string($name)) {
                continue;
            }
            if (str_ends_with($name, '/')) {
                $target->addEmptyDir($name);

                continue;
            }
            $content = $source->getFromIndex($index);
            if ($content === false) {
                continue;
            }
            $target->addFromString($name, str_ends_with(strtolower($name), '.csv')
                ? SafeCsv::sanitizeString($content)
                : $content);
        }

        $source->close();
        $target->close();
        readfile($temporaryPath);
        @unlink($temporaryPath);
    }

    private function streamOriginal(string $storagePath): void
    {
        $stream = Storage::disk('local')->readStream($storagePath);
        if ($stream === false) {
            return;
        }
        fpassthru($stream);
        fclose($stream);
    }
}
