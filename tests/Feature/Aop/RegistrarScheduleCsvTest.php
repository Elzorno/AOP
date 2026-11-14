<?php

namespace Tests\Feature\Aop;

use App\Models\CatalogCourse;
use App\Models\Instructor;
use App\Models\MeetingBlock;
use App\Models\Offering;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use App\Support\SafeCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrarScheduleCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_registrar_csv_matches_the_expected_schedule_spreadsheet_layout(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = Term::create([
            'code' => '2027SP',
            'name' => 'Spring 2027',
            'starts_on' => '2027-01-11',
            'ends_on' => null,
            'is_active' => true,
            'status' => 'draft',
            'weeks_in_term' => 15,
            'slot_minutes' => 15,
            'buffer_minutes' => 10,
            'schedule_locked' => true,
        ]);

        $course = CatalogCourse::create([
            'code' => 'ISCS-1600',
            'title' => 'Intro to Basic Scripting for Security',
            'credits' => 3,
            'is_active' => true,
        ]);
        $offering = Offering::create([
            'term_id' => $term->id,
            'catalog_course_id' => $course->id,
        ]);
        $staffSection = Section::create([
            'offering_id' => $offering->id,
            'section_code' => '01',
            'instructor_is_staff' => true,
            'modality' => 'IN_PERSON',
            'section_capacity' => 16,
            'enrolled_count' => 0,
        ]);
        $room = Room::create([
            'name' => 'ATC-228',
            'building' => 'Advanced Technology Center',
            'room_number' => '228',
            'is_active' => true,
        ]);
        MeetingBlock::create([
            'section_id' => $staffSection->id,
            'type' => 'LECTURE',
            'days_json' => ['Wed', 'Mon'],
            'starts_at' => '14:30',
            'ends_at' => '16:45',
            'room_id' => $room->id,
        ]);

        $onlineCourse = CatalogCourse::create([
            'code' => 'ISCS-3200',
            'title' => 'Information Security Auditing',
            'credits' => 3,
            'is_active' => true,
        ]);
        $onlineOffering = Offering::create([
            'term_id' => $term->id,
            'catalog_course_id' => $onlineCourse->id,
        ]);
        $instructor = Instructor::create([
            'name' => 'Lucas, Barry E.',
            'is_active' => true,
        ]);
        Section::create([
            'offering_id' => $onlineOffering->id,
            'section_code' => '90',
            'instructor_id' => $instructor->id,
            'modality' => 'ONLINE',
            'section_capacity' => 16,
            'enrolled_count' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.reports.exportRegistrar'));

        $response->assertOk()->assertDownload('aop_2027sp_registrar_schedule.csv');
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertCount(2, $rows);

        $staffRow = collect($rows)->firstWhere('Course-Section', 'ISCS-1600-01');
        $this->assertNotNull($staffRow);
        $this->assertSame('Intro to Basic Scripting for Security', $staffRow['Title']);
        $this->assertSame('STAFF', $staffRow['Faculty']);
        $this->assertSame('3.00', $staffRow['Credits']);
        $this->assertSame('16/16', $staffRow['Seats Open']);
        $this->assertSame('Mon, Wed', $staffRow['Days']);
        $this->assertSame('2:30 PM', $staffRow['Start Time']);
        $this->assertSame('4:45 PM', $staffRow['End Time']);
        $this->assertSame('1/11/2027', $staffRow['Start Date']);
        $this->assertSame('4/23/2027', $staffRow['End Date']);
        $this->assertSame('Advanced Technology Center', $staffRow['Building']);
        $this->assertSame('228', $staffRow['Room']);
        $this->assertSame(
            "Mon, Wed\n2:30-4:45 PM\n1/11/2027 - 4/23/2027 Main Campus Advanced Technology Center - 228",
            $staffRow['Registrar Schedule'],
        );

        $onlineRow = collect($rows)->firstWhere('Course-Section', 'ISCS-3200-90');
        $this->assertNotNull($onlineRow);
        $this->assertSame('Lucas, Barry E.', $onlineRow['Faculty']);
        $this->assertSame('ONLINE', $onlineRow['Modality']);
        $this->assertSame('', $onlineRow['Days']);
        $this->assertSame('', $onlineRow['Start Time']);
        $this->assertSame('Online Instruction', $onlineRow['Building']);
        $this->assertSame('ONLIN', $onlineRow['Room']);
        $this->assertSame(
            '1/11/2027 - 4/23/2027 Main Campus Online Instruction - ONLIN',
            $onlineRow['Registrar Schedule'],
        );
    }

    public function test_registrar_csv_neutralizes_formula_cells_without_changing_ordinary_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $term = Term::create([
            'code' => '2027FA', 'name' => 'Fall 2027', 'starts_on' => '2027-08-23',
            'ends_on' => '2027-12-10', 'is_active' => true, 'status' => 'draft',
            'weeks_in_term' => 16, 'slot_minutes' => 15, 'buffer_minutes' => 10,
        ]);
        $course = CatalogCourse::create([
            'code' => 'SAFE-100', 'title' => '=HYPERLINK("https://example.test","Open")',
            'credits' => 3, 'is_active' => true,
        ]);
        $offering = Offering::create(['term_id' => $term->id, 'catalog_course_id' => $course->id]);
        Section::create([
            'offering_id' => $offering->id, 'section_code' => '01',
            'instructor_is_staff' => true, 'modality' => 'ONLINE',
            'notes' => '  +SUM(1,1)',
        ]);

        $response = $this->actingAs($admin)->get(route('aop.schedule.reports.exportRegistrar'));
        $rows = $this->parseCsv($response->streamedContent());

        $this->assertSame("'=HYPERLINK(\"https://example.test\",\"Open\")", $rows[0]['Title']);
        $this->assertSame("'  +SUM(1,1)", $rows[0]['Notes']);
        $this->assertSame('SAFE-100-01', $rows[0]['Course-Section']);
        $this->assertSame('STAFF', $rows[0]['Faculty']);

        $this->assertSame("'\u{2003}@SUM(1,1)", SafeCsv::escapeCell("\u{2003}@SUM(1,1)"));
        $this->assertSame("'\n-2+2", SafeCsv::escapeCell("\n-2+2"));
    }

    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);

        $headers = fgetcsv($stream);
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $rows = [];

        while (($values = fgetcsv($stream)) !== false) {
            $rows[] = array_combine($headers, $values);
        }

        fclose($stream);

        return $rows;
    }
}
