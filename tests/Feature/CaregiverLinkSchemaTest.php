<?php

namespace Tests\Feature;

use App\Application\Caregiving\CaregiverLinks;
use App\Enums\UserRole;
use App\Models\CaregiverLink;
use App\Policies\CaregiverLinkPolicy;
use App\Policies\PatientDataPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaregiverLinkTables;
use Tests\TestCase;

class CaregiverLinkSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CaregiverLinkTables::create();
    }

    public function test_additive_schema_preserves_users_and_enforces_two_slots(): void
    {
        $this->assertTrue(Schema::hasTable('caregiver_links'));
        $patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $before = DB::table('users')->where('id', $patient->id)->first();
        for ($slot = 1; $slot <= 2; $slot++) {
            $caregiver = CaregiverLinkTables::user('Carer'.$slot, UserRole::Caregiver);
            DB::table('caregiver_links')->insert($this->row($patient->id, $caregiver->id, $slot));
        }
        $this->assertEquals($before, DB::table('users')->where('id', $patient->id)->first());
        $this->assertSame(2, DB::table('caregiver_links')->count());
        $this->expectException(QueryException::class);
        DB::table('caregiver_links')->insert($this->row($patient->id, CaregiverLinkTables::user('Carer3', UserRole::Caregiver)->id, 3));
    }

    #[DataProvider('invalidRows')]
    public function test_database_rejects_invalid_rows_without_application_validation(array $changes): void
    {
        $this->assertTrue(Schema::hasTable('caregiver_links'));
        $patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $caregiver = CaregiverLinkTables::user('Carer1', UserRole::Caregiver);
        $this->expectException(QueryException::class);
        DB::table('caregiver_links')->insert([...$this->row($patient->id, $caregiver->id), ...$changes]);
    }

    public static function invalidRows(): array
    {
        return [
            'bad status' => [['status' => 'accepted']], 'live without slot' => [['slot_number' => null]],
            'active without acceptance' => [['status' => 'active']],
            'pending with acceptance' => [['accepted_at' => '2026-11-08 12:00:00+00']],
            'terminal without metadata' => [['status' => 'cancelled', 'slot_number' => null]],
            'terminal occupying slot' => [['status' => 'declined', 'ended_at' => '2026-11-08 12:00:00+00', 'ended_by' => 2]],
            'missing participant' => [['caregiver_id' => 999999]],
            'same participants' => [['patient_id' => 2]],
            'active null slot' => [['status' => 'active', 'slot_number' => null, 'accepted_at' => '2099-01-01 00:00:00+00']],
            'revoked without acceptance' => [['status' => 'revoked', 'slot_number' => null, 'ended_at' => '2099-01-01 00:00:00+00', 'ended_by' => 1]],
            'decline wrong actor' => [['status' => 'declined', 'slot_number' => null, 'ended_at' => '2099-01-01 00:00:00+00', 'ended_by' => 1]],
            'cancel wrong actor' => [['status' => 'cancelled', 'slot_number' => null, 'ended_at' => '2099-01-01 00:00:00+00', 'ended_by' => 2]],
        ];
    }

    #[DataProvider('duplicateModes')]
    public function test_database_rejects_duplicate_live_pair_or_slot(bool $samePair): void
    {
        $this->assertTrue(Schema::hasTable('caregiver_links'));
        $p = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $c = CaregiverLinkTables::user('Carer1', UserRole::Caregiver);
        $other = CaregiverLinkTables::user('Carer2', UserRole::Caregiver);
        DB::table('caregiver_links')->insert($this->row($p->id, $c->id));
        $this->expectException(QueryException::class);
        DB::table('caregiver_links')->insert($this->row($p->id, $samePair ? $c->id : $other->id, $samePair ? 2 : 1));
    }

    public static function duplicateModes(): array
    {
        return [[true], [false]];
    }

    public function test_policy_grants_access_only_after_acceptance_and_removes_it_on_revocation(): void
    {
        $this->assertTrue(Schema::hasTable('caregiver_links'));
        $p = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $c = CaregiverLinkTables::user('Carer1', UserRole::Caregiver);
        $outsider = CaregiverLinkTables::user('Other1', UserRole::Caregiver);
        $flow = app(CaregiverLinks::class);
        $link = $flow->invite($p, ' cArEr1 ');
        $policy = new PatientDataPolicy;
        $this->assertTrue($policy->view($p, $p));
        $this->assertFalse($policy->view($c, $p));
        $this->assertFalse((new CaregiverLinkPolicy)->accept($p, $link));
        $flow->transition($c, $link->id, 'accept');
        $this->assertTrue($policy->view($c, $p));
        $this->assertFalse($policy->view($outsider, $p));
        $flow->transition($p, $link->id, 'revoke');
        $this->assertFalse($policy->view($c, $p));
        $new = $flow->invite($p, 'Carer1');
        $this->assertNotSame($link->id, $new->id);
        $this->assertSame(2, CaregiverLink::count());
    }

    private function row(int $patient, int $caregiver, int $slot = 1): array
    {
        return ['id' => (string) Str::uuid(), 'patient_id' => $patient, 'caregiver_id' => $caregiver,
            'status' => 'pending', 'slot_number' => $slot, 'invited_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }
}
