<?php

namespace Tests\Feature\Aop;

use App\Models\SchedulePublication;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class HistoricalPublicationDownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_csv_download_is_sanitized_without_rewriting_snapshot(): void
    {
        [$term, $publication] = $this->publication();
        $path = $publication->storage_base_path.'/term_schedule.csv';
        $original = "Title,Notes\n=1+1,ordinary\n";
        Storage::disk('local')->put($path, $original);

        $response = $this->get(route('public.schedule.download.term', [
            $term->code, $publication->version, $publication->public_token,
        ]));

        $response->assertOk();
        $this->assertStringContainsString("'=1+1", $response->streamedContent());
        $this->assertSame($original, Storage::disk('local')->get($path));
    }

    public function test_historical_zip_csv_members_are_sanitized_and_other_files_are_preserved(): void
    {
        [$term, $publication] = $this->publication();
        $storagePath = $publication->storage_base_path.'/instructors.zip';
        Storage::disk('local')->makeDirectory($publication->storage_base_path);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($storagePath), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('instructor.csv', "Name,Notes\nAlice,@SUM(1,1)\n");
        $zip->addFromString('readme.txt', 'historical metadata');
        $zip->close();
        $originalHash = hash_file('sha256', Storage::disk('local')->path($storagePath));

        $response = $this->get(route('public.schedule.download.instructors', [
            $term->code, $publication->version, $publication->public_token,
        ]));
        $download = tempnam(sys_get_temp_dir(), 'aop-test-zip-');
        file_put_contents($download, $response->streamedContent());
        $downloadZip = new ZipArchive;
        $this->assertTrue($downloadZip->open($download));

        $this->assertStringContainsString("'@SUM(1,1)", $downloadZip->getFromName('instructor.csv'));
        $this->assertSame('historical metadata', $downloadZip->getFromName('readme.txt'));
        $downloadZip->close();
        @unlink($download);
        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('local')->path($storagePath)));
    }

    private function publication(): array
    {
        Storage::fake('local');
        $term = Term::create([
            'code' => '2026FA', 'name' => 'Fall 2026', 'is_active' => false,
            'status' => 'draft', 'weeks_in_term' => 16, 'slot_minutes' => 15,
            'buffer_minutes' => 10,
        ]);
        $publication = SchedulePublication::create([
            'term_id' => $term->id, 'version' => 1, 'published_at' => now(),
            'storage_base_path' => 'aop/published/2026FA/v1',
            'public_token' => str_repeat('a', 64),
        ]);

        return [$term, $publication];
    }
}
