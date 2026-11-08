<?php

namespace Tests\Feature;

use App\Http\Requests\RegisterUserRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UserTable;
use Tests\TestCase;

class RegistrationValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        UserTable::create();
    }

    public function test_registration_contract_is_available_without_an_endpoint(): void
    {
        $this->assertTrue(class_exists(RegisterUserRequest::class));
    }

    #[DataProvider('requiredFields')]
    public function test_registration_requires_each_profile_field(string $field): void
    {
        $input = $this->validInput();
        unset($input[$field]);
        $this->assertArrayHasKey($field, $this->errors($input));
    }

    public static function requiredFields(): array
    {
        return array_map(fn ($field) => [$field], [
            'username', 'first_name', 'last_name', 'email', 'password',
            'password_confirmation', 'phone_number', 'country', 'region', 'address', 'role',
        ]);
    }

    #[DataProvider('validRoles')]
    public function test_accepts_existing_roles_and_unicode_names(string $role): void
    {
        $this->assertSame([], $this->errors([...$this->validInput(), 'role' => $role]));
    }

    public static function validRoles(): array
    {
        return [['patient'], ['caregiver']];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_invalid_registration_values(string $field, mixed $value): void
    {
        $input = [...$this->validInput(), $field => $value];
        if ($field === 'password') {
            // An invalid password must fail its own policy, not just confirmation.
            $input['password_confirmation'] = $value;
        }
        $this->assertArrayHasKey($field, $this->errors($input));
    }

    public static function invalidValues(): array
    {
        return [
            'short username' => ['username', 'abc'],
            'long username' => ['username', str_repeat('a', 16)],
            'username spaces' => ['username', 'user name'],
            'username unicode' => ['username', 'مستخدم'],
            'username slash' => ['username', 'user/name'],
            'username newline' => ['username', "user\nname"],
            'invalid email' => ['email', 'not-an-email'],
            'long email' => ['email', str_repeat('a', 243).'@example.com'],
            'array email' => ['email', []],
            'blank first name' => ['first_name', '   '],
            'nonbreaking space first name' => ['first_name', "\u{00A0}"],
            'em space last name' => ['last_name', "\u{2003}"],
            'long first name' => ['first_name', str_repeat('a', 81)],
            'long last name' => ['last_name', str_repeat('a', 81)],
            'long country' => ['country', str_repeat('a', 81)],
            'long region' => ['region', str_repeat('a', 81)],
            'long address' => ['address', str_repeat('a', 251)],
            'invalid role' => ['role', 'admin'],
            'wrong role casing' => ['role', 'Patient'],
            'missing lowercase' => ['password', 'ABCDEFGHI1!'],
            'missing uppercase' => ['password', 'abcdefghi1!'],
            'missing digit' => ['password', 'Abcdefghi!'],
            'missing special' => ['password', 'Abcdefghi1'],
            'short password' => ['password', 'Abcde1!x'],
            'apostrophe is not an allowed special character' => ['password', "Abcdef12'"],
            'non ASCII uppercase does not satisfy uppercase' => ['password', 'Äbcdefgh1!'],
            'confirmation mismatch' => ['password_confirmation', 'Different1!'],
            'phone without prefix' => ['phone_number', '201012345678'],
            'phone with spaces' => ['phone_number', '+20 1012345678'],
            'phone too short' => ['phone_number', '+123456'],
            'phone too long' => ['phone_number', '+1234567890123456'],
            'phone letters' => ['phone_number', '+2010abc5678'],
        ];
    }

    public function test_normalizes_text_without_lowercasing_username_or_trimming_password(): void
    {
        $password = ' Abcdef12! ';
        $validated = $this->validate([
            ...$this->validInput(), 'username' => ' Amina_1 ', 'email' => ' AMINA@EXAMPLE.COM ',
            'first_name' => ' أمينة ', 'password' => $password, 'password_confirmation' => $password,
            'id' => 99, 'email_verified_at' => '2026-01-01',
        ]);
        $this->assertSame('Amina_1', $validated['username']);
        $this->assertSame('amina@example.com', $validated['email']);
        $this->assertSame('أمينة', $validated['first_name']);
        $this->assertSame($password, $validated['password']);
        $this->assertArrayNotHasKey('id', $validated);
        $this->assertArrayNotHasKey('email_verified_at', $validated);
    }

    public function test_identity_checks_match_database_case_and_space_canonicalization(): void
    {
        DB::table('users')->insert(['name' => 'Existing user', 'password' => 'unused-fixture-hash', 'email' => ' Amina@Example.com ', 'username' => ' Amina_1 ']);
        $errors = $this->errors($this->validInput());
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('username', $errors);
    }

    public function test_validates_boundaries_and_the_flutter_password_punctuation_set(): void
    {
        $input = [...$this->validInput(), 'username' => str_repeat('a', 15),
            'first_name' => str_repeat('a', 80), 'last_name' => str_repeat('b', 80),
            'country' => str_repeat('c', 80), 'region' => str_repeat('d', 80),
            'address' => str_repeat('e', 250), 'email' => str_repeat('a', 242).'@example.com',
            'phone_number' => '+123456789012345'];
        foreach (str_split('!@#$%^&*()_+-=[]{};:"\\|,.<>/?`~') as $punctuation) {
            $password = 'Abcdef12'.$punctuation;
            $this->assertSame([], $this->errors([...$input, 'password' => $password, 'password_confirmation' => $password]));
        }
    }

    public function test_preserves_dart_utf16_password_length_and_minimum_phone_format(): void
    {
        $this->assertSame([], $this->errors([...$this->validInput(), 'password' => 'Aa1!😀😀x',
            'password_confirmation' => 'Aa1!😀😀x', 'phone_number' => '+1234567']));
    }

    public function test_trims_unicode_whitespace_like_flutter_for_profile_fields(): void
    {
        $validated = $this->validate([...$this->validInput(), 'username' => "\u{00A0}Amina_1\u{2003}",
            'first_name' => "\u{2003}أمينة\u{00A0}", 'email' => "\u{FEFF}AMINA@EXAMPLE.COM\u{00A0}"]);
        $this->assertSame('Amina_1', $validated['username']);
        $this->assertSame('أمينة', $validated['first_name']);
        $this->assertSame('amina@example.com', $validated['email']);
    }

    private function validInput(): array
    {
        return ['username' => 'Amina_1', 'first_name' => 'أمينة', 'last_name' => "O'Neill",
            'email' => 'amina@example.com', 'password' => 'Abcdef12!', 'password_confirmation' => 'Abcdef12!',
            'phone_number' => '+201012345678', 'country' => 'Egypt', 'region' => 'القاهرة',
            'address' => '12 Nile Street', 'role' => 'patient'];
    }

    private function validate(array $input): array
    {
        $request = RegisterUserRequest::create('/unused', 'POST', $input);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request->validated();
    }

    private function errors(array $input): array
    {
        try {
            $this->validate($input);
        } catch (ValidationException $error) {
            return $error->errors();
        }

        return [];
    }
}
