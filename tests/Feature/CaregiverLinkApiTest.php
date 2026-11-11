<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\PatientDataPolicy;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CaregiverLinkTables;
use Tests\TestCase;

class CaregiverLinkApiTest extends TestCase
{
    private User $patient;

    private User $caregiver;

    protected function setUp(): void
    {
        parent::setUp();
        CaregiverLinkTables::create();
        $this->patient = CaregiverLinkTables::user('Patient1', UserRole::Patient);
        $this->caregiver = CaregiverLinkTables::user('Caregiver1', UserRole::Caregiver);
    }

    public function test_requires_authentication_and_exact_minimal_lookup(): void
    {
        $this->getJson('/api/caregiver-links')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->asUser($this->patient);
        $this->postJson('/api/caregivers/lookup', ['username' => ' CAREGIVER1 '])->assertOk()
            ->assertExactJson(['code' => 'caregiver-found', 'data' => ['caregiver' => ['name' => 'Caregiver1 Display', 'username' => 'Caregiver1']]]);
        foreach (['Caregiver', 'Patient1', 'Absent1'] as $username) {
            $this->postJson('/api/caregivers/lookup', compact('username'))->assertNotFound()->assertJsonPath('code', 'caregiver-not-found');
        }
        $this->asUser($this->caregiver);
        $this->postJson('/api/caregivers/lookup', ['username' => 'Caregiver1'])->assertForbidden();
        foreach (['Patient1', 'Caregiver1'] as $username) {
            $this->postJson('/api/caregiver-links', ['caregiver_username' => $username])->assertForbidden();
        }
    }

    #[DataProvider('endingActions')]
    public function test_lifecycle_history_reinvitation_and_public_serialization(string $action, string $status, bool $caregiverActs, bool $accept): void
    {
        $this->asUser($this->patient);
        $created = $this->postJson('/api/caregiver-links', ['caregiver_username' => 'caregiver1'])->assertCreated();
        $id = $created->json('data.link.id');
        $created->assertJsonPath('data.link.status', 'pending')->assertJsonPath('data.link.allowed_actions', ['cancel']);
        $this->asUser($this->caregiver);
        $this->getJson('/api/caregiver-links')->assertOk()->assertJsonPath('data.links.0.allowed_actions', ['accept', 'decline']);
        $this->assertFalse(Gate::forUser($this->caregiver)->allows('view-linked-patient', $this->patient));
        if ($accept) {
            $this->postJson("/api/caregiver-links/$id/accept")->assertOk()->assertJsonPath('data.link.status', 'active');
            $this->assertTrue(Gate::forUser($this->caregiver)->allows('view-linked-patient', $this->patient));
        }
        $this->asUser($caregiverActs ? $this->caregiver : $this->patient);
        $ended = $this->postJson("/api/caregiver-links/$id/$action")->assertOk()->assertJsonPath('data.link.status', $status)
            ->assertJsonPath('data.link.allowed_actions', []);
        $this->assertNotNull($ended->json('data.link.ended_at'));
        $this->assertFalse((new PatientDataPolicy)->view($this->caregiver, $this->patient));
        $this->postJson("/api/caregiver-links/$id/$action")->assertConflict()->assertJsonPath('code', 'invalid-relationship-state');
        $this->asUser($this->patient);
        $this->getJson('/api/caregiver-links')->assertJsonCount(0, 'data.links')->assertJsonPath('data.slots.available', 2);
        $history = $this->getJson('/api/caregiver-links?status=all')->assertJsonCount(1, 'data.links');
        foreach (['email', 'phone_number', 'address', 'password', 'patient_id', 'caregiver_id', 'ended_by', 'slot_number'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $history->getContent());
        }
        $new = $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver1'])->assertCreated();
        $this->assertNotSame($id, $new->json('data.link.id'));
    }

    public static function endingActions(): array
    {
        return [['decline', 'declined', true, false], ['cancel', 'cancelled', false, false],
            ['revoke', 'revoked', true, true], ['revoke', 'revoked', false, true]];
    }

    public function test_two_slots_duplicates_and_counts_independent_of_pagination(): void
    {
        $this->asUser($this->patient);
        $first = $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver1'])->assertCreated()->json('data.link.id');
        foreach ([2, 3, 4] as $n) {
            CaregiverLinkTables::user('Caregiver'.$n, UserRole::Caregiver);
        }
        foreach ([2] as $n) {
            $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver'.$n])->assertCreated();
        }
        $this->postJson('/api/caregiver-links', ['caregiver_username' => 'caregiver1'])->assertConflict()->assertJsonPath('code', 'duplicate-live-relationship');
        $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver3'])->assertConflict()->assertJsonPath('code', 'caregiver-slots-full');
        $this->getJson('/api/caregiver-links?per_page=1')->assertJsonCount(1, 'data.links')->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.slots.maximum', 2)->assertJsonPath('data.slots.occupied', 2)->assertJsonPath('data.slots.available', 0);
        $this->getJson('/api/caregiver-links?status=active')->assertJsonCount(0, 'data.links')->assertJsonPath('data.slots.occupied', 2);
        $this->asUser($this->caregiver);
        $this->postJson("/api/caregiver-links/$first/accept")->assertOk();
        foreach ([2] as $n) {
            $this->asUser(User::where('username', 'Caregiver'.$n)->firstOrFail());
            $id = $this->getJson('/api/caregiver-links')->assertOk()->json('data.links.0.id');
            $this->postJson("/api/caregiver-links/$id/accept")->assertOk();
        }
        $this->asUser($this->patient);
        $this->getJson('/api/caregiver-links?status=active')->assertJsonCount(2, 'data.links')->assertJsonPath('data.slots.occupied', 2);
        $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver4'])->assertConflict();
    }

    public function test_caregiver_can_link_with_multiple_patients_without_global_limit(): void
    {
        for ($n = 1; $n <= 4; $n++) {
            $p = $n === 1 ? $this->patient : CaregiverLinkTables::user('Patient'.$n, UserRole::Patient);
            $this->asUser($p);
            $id = $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver1'])->assertCreated()->json('data.link.id');
            $this->asUser($this->caregiver);
            $this->postJson("/api/caregiver-links/$id/accept")->assertOk();
        }
        $this->getJson('/api/caregiver-links?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data.links')->assertJsonPath('data.pagination.total', 4);
    }

    public function test_unrelated_resources_and_wrong_actions_are_denied(): void
    {
        $this->asUser($this->patient);
        $id = $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver1'])->assertCreated()->json('data.link.id');
        foreach (['accept', 'decline'] as $action) {
            $this->postJson("/api/caregiver-links/$id/$action")->assertForbidden();
        }
        $this->postJson("/api/caregiver-links/$id/revoke")->assertConflict();
        $this->asUser($this->caregiver);
        $this->postJson("/api/caregiver-links/$id/cancel")->assertForbidden();
        foreach ([UserRole::Patient, UserRole::Caregiver] as $role) {
            $this->asUser(CaregiverLinkTables::user('Other'.$role->value, $role));
            $this->getJson('/api/caregiver-links?status=all')->assertJsonCount(0, 'data.links');
            foreach (['accept', 'decline', 'cancel', 'revoke'] as $action) {
                foreach ([$id, 'not-a-uuid', '00000000-0000-4000-8000-000000000000'] as $key) {
                    $this->postJson("/api/caregiver-links/$key/$action")->assertNotFound()->assertJsonPath('code', 'relationship-not-found');
                }
            }
        }
    }

    public function test_payload_manipulation_validation_and_lookup_throttling(): void
    {
        $this->asUser($this->patient);
        foreach (['role', 'patient_id', 'caregiver_id', 'status', 'slot_number', 'accepted_at'] as $field) {
            $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Caregiver1', $field => 'untrusted'])->assertUnprocessable()->assertJsonPath('code', 'validation-failed');
        }
        $this->postJson('/api/caregivers/lookup', ['username' => 'car*'])->assertUnprocessable();
        $this->postJson('/api/caregiver-links', ['caregiver_username' => 'Patient1'])->assertNotFound();
        $this->getJson('/api/caregiver-links?status=bad')->assertUnprocessable();
        $this->getJson('/api/caregiver-links?per_page=51')->assertUnprocessable();
        // The earlier invalid lookup also consumes the authenticated lookup budget.
        for ($n = 0; $n < 9; $n++) {
            $this->postJson('/api/caregivers/lookup', ['username' => 'Caregiver1'])->assertOk();
        }
        $this->postJson('/api/caregivers/lookup', ['username' => 'Caregiver1'])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'too-many-requests');
    }

    private function asUser(User $user): void
    {
        // Exercise the actual Sanctum bearer guard, not client-supplied roles.
        $this->withToken($user->createToken('link-test', ['*'], now()->addHour())->plainTextToken);
        $this->app['auth']->forgetGuards();
    }
}
