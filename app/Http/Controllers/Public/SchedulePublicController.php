<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\SchedulePublication;
use App\Models\Term;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SchedulePublicController extends Controller
{
    public function show(string $termCode, ?int $version = null, string $token = '')
    {
        $term = Term::where('code', $termCode)->first();
        abort_if(!$term, 404);

        $publication = SchedulePublication::where('term_id', $term->id)
            ->when($version !== null, fn($q) => $q->where('version', $version), fn($q) => $q->orderByDesc('version'))
            ->first();

        abort_if(!$publication, 404);
        abort_if(!$publication->public_token || $publication->public_token !== $token, 404);

        return view('public.schedule.show', [
            'term' => $term,
            'publication' => $publication,
            'downloads' => [
                'term' => route('public.schedule.download.term', [$term->code, $publication->version, $publication->public_token]),
                'instructors_zip' => route('public.schedule.download.instructors', [$term->code, $publication->version, $publication->public_token]),
                'rooms_zip' => route('public.schedule.download.rooms', [$term->code, $publication->version, $publication->public_token]),
            ],
        ]);
    }

    public function downloadTerm(string $termCode, int $version, string $token): StreamedResponse
    {
        $pub = $this->resolvePublication($termCode, $version, $token);
        return $this->downloadLocalFile($pub->storage_base_path . '/term_schedule.csv', sprintf('aop_%s_v%d_term_schedule.csv', $termCode, $pub->version));
    }

    public function downloadInstructorsZip(string $termCode, int $version, string $token): StreamedResponse
    {
        $pub = $this->resolvePublication($termCode, $version, $token);
        return $this->downloadLocalFile($pub->storage_base_path . '/instructors.zip', sprintf('aop_%s_v%d_instructors.zip', $termCode, $pub->version));
    }

    public function downloadRoomsZip(string $termCode, int $version, string $token): StreamedResponse
    {
        $pub = $this->resolvePublication($termCode, $version, $token);
        return $this->downloadLocalFile($pub->storage_base_path . '/rooms.zip', sprintf('aop_%s_v%d_rooms.zip', $termCode, $pub->version));
    }

    private function resolvePublication(string $termCode, int $version, string $token): SchedulePublication
    {
        $term = Term::where('code', $termCode)->first();
        abort_if(!$term, 404);

        $publication = SchedulePublication::where('term_id', $term->id)->where('version', $version)->first();
        abort_if(!$publication, 404);
        abort_if(!$publication->public_token || $publication->public_token !== $token, 404);

        return $publication;
    }

    private function downloadLocalFile(string $storagePath, string $downloadName): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($storagePath), 404);

        return response()->streamDownload(function () use ($storagePath) {
            $stream = Storage::disk('local')->readStream($storagePath);
            if (!$stream) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $downloadName);
    }
}
