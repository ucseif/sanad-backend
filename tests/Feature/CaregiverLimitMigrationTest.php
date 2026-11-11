<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaregiverLinkTables;
use Tests\Support\UserTable;
use Tests\TestCase;

class CaregiverLimitMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UserTable::create();
        (require database_path('migrations/2026_11_08_000000_create_caregiver_links_table.php'))->up();
    }

    #[DataProvider('existingSlots')]
    public function test_upgrade_preserves_relationships_and_compacts_only_slot_three(array $slots): void
    {
        $patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        foreach ($slots as $slot) {
            $caregiver = CaregiverLinkTables::user('Carer'.$slot, UserRole::Caregiver);
            DB::table('caregiver_links')->insert($this->row($patient->id, $caregiver->id, $slot));
        }
        $historyCarer = CaregiverLinkTables::user('History1', UserRole::Caregiver);
        DB::table('caregiver_links')->insert([...$this->row($patient->id, $historyCarer->id, 1),
            'status' => 'cancelled', 'slot_number' => null, 'ended_at' => now(), 'ended_by' => $patient->id]);
        $before = DB::table('caregiver_links')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->migration()->up();
        $after = DB::table('caregiver_links')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        foreach ($before as $index => $row) {
            $this->assertSame(array_diff_key($row, ['slot_number' => true]), array_diff_key($after[$index], ['slot_number' => true]));
            if ($row['slot_number'] !== 3) {
                $this->assertSame($row['slot_number'], $after[$index]['slot_number']);
            }
        }
        $this->assertSame(0, DB::table('caregiver_links')->where('slot_number', '>', 2)->count());
        $extra = CaregiverLinkTables::user('Extra1', UserRole::Caregiver);
        $this->expectException(QueryException::class);
        DB::table('caregiver_links')->insert($this->row($patient->id, $extra->id, 3));
    }

    public static function existingSlots(): array
    {
        return [[[3]], [[1, 3]], [[2, 3]], [[1, 2]], [[]]];
    }

    public function test_upgrade_refuses_three_live_links_without_modifying_any_relationship(): void
    {
        $patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        foreach ([1, 2, 3] as $slot) {
            $c = CaregiverLinkTables::user('Carer'.$slot, UserRole::Caregiver);
            DB::table('caregiver_links')->insert($this->row($patient->id, $c->id, $slot));
        }
        $before = DB::table('caregiver_links')->orderBy('id')->get();
        try {
            $this->migration()->up();
            $this->fail('Three live links require explicit manual resolution, never automatic revocation.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('more than two', $error->getMessage());
        }
        $this->assertEquals($before, DB::table('caregiver_links')->orderBy('id')->get());
    }

    public function test_rollback_restores_old_capacity_without_changing_existing_rows(): void
    {
        $patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $first = CaregiverLinkTables::user('Carer1', UserRole::Caregiver);
        DB::table('caregiver_links')->insert($this->row($patient->id, $first->id, 1));
        $before = DB::table('caregiver_links')->get();
        $this->migration()->up();
        $this->migration()->down();
        $this->assertEquals($before, DB::table('caregiver_links')->get());
        foreach ([2, 3] as $slot) {
            $c = CaregiverLinkTables::user('Carer'.$slot, UserRole::Caregiver);
            DB::table('caregiver_links')->insert($this->row($patient->id, $c->id, $slot));
        }
        $this->assertSame(3, DB::table('caregiver_links')->count());
    }

    private function migration(): Migration
    {
        $path = database_path('migrations/2026_11_09_000000_limit_caregiver_links_to_two_slots.php');
        $this->assertFileExists($path);

        return require $path;
    }

    private function row(int $patient, int $caregiver, int $slot): array
    {
        return ['id' => (string) Str::uuid(), 'patient_id' => $patient, 'caregiver_id' => $caregiver,
            'status' => $slot === 2 ? 'active' : 'pending', 'slot_number' => $slot,
            'invited_at' => now(), 'accepted_at' => $slot === 2 ? now() : null, 'created_at' => now(), 'updated_at' => now()];
    }
}
