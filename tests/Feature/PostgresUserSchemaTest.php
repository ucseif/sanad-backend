<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real PostgreSQL migrations run only in a verified, rolled-back test schema. */
class PostgresUserSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Only real migrations, in a new schema within a rolled-back transaction.
        (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
    }

    public function test_additive_migration_preserves_legacy_data_without_fabricating_profiles(): void
    {
        DB::table('users')->insert($this->legacyRow());
        $before = (array) DB::table('users')->first();
        $this->migration()->up();
        $after = (array) DB::table('users')->first();
        $this->assertSame($before, array_intersect_key($after, $before));
        foreach (['username', 'first_name', 'last_name', 'phone_number', 'country', 'region', 'address', 'role'] as $field) {
            $this->assertArrayHasKey($field, $after);
            $this->assertNull($after[$field]);
        }
    }

    #[DataProvider('duplicateIdentities')]
    public function test_database_rejects_duplicates_bypassing_model_and_validation(string $column, string $duplicate): void
    {
        $this->migration()->up();
        DB::table('users')->insert($this->completeRow());
        $second = [...$this->completeRow(), 'email' => 'other@example.com', 'username' => 'Other_1', $column => $duplicate];
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('23505');
        DB::table('users')->insert($second);
    }

    public static function duplicateIdentities(): array
    {
        return [
            'email case' => ['email', 'AMINA@EXAMPLE.COM'],
            'email spaces' => ['email', ' amina@example.com '],
            'username case' => ['username', 'AMINA_1'],
            'username spaces' => ['username', ' Amina_1 '],
        ];
    }

    #[DataProvider('supportedRoles')]
    public function test_database_accepts_both_roles_and_does_not_make_phones_unique(string $role): void
    {
        $this->migration()->up();
        DB::table('users')->insert($this->completeRow());
        DB::table('users')->insert([...$this->completeRow(), 'email' => 'other@example.com', 'username' => 'Other_1', 'role' => $role]);
        $this->assertSame(2, DB::table('users')->count());
    }

    public static function supportedRoles(): array
    {
        return [['patient'], ['caregiver']];
    }

    public function test_database_rejects_an_invalid_role_bypassing_enum_and_request(): void
    {
        $this->migration()->up();
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('23514');
        DB::table('users')->insert([...$this->completeRow(), 'role' => 'admin']);
    }

    public function test_conflicting_existing_emails_stop_migration_without_altering_records(): void
    {
        DB::table('users')->insert($this->legacyRow());
        DB::table('users')->insert([...$this->legacyRow(), 'email' => 'AMINA@EXAMPLE.COM']);
        try {
            $this->migration()->up();
            $this->fail('Conflicting existing emails must stop migration.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('conflicting email identities', $error->getMessage());
        }
        $this->assertSame(2, DB::table('users')->count());
        $this->assertFalse(Schema::hasColumn('users', 'username'));
    }

    public function test_rollback_preserves_original_rows_and_authentication_fields(): void
    {
        DB::table('users')->insert($this->legacyRow());
        $before = (array) DB::table('users')->first();
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $this->assertSame($before, (array) DB::table('users')->first());
        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php');
    }

    private function legacyRow(): array
    {
        return ['name' => 'Existing name', 'email' => 'amina@example.com', 'password' => 'existing-hash', 'remember_token' => 'existing-token'];
    }

    private function completeRow(): array
    {
        return [...$this->legacyRow(), 'name' => 'Amina Hassan', 'username' => 'Amina_1',
            'first_name' => 'Amina', 'last_name' => 'Hassan', 'phone_number' => '+201012345678',
            'country' => 'Egypt', 'region' => 'Cairo', 'address' => '12 Nile Street', 'role' => 'patient'];
    }
}
