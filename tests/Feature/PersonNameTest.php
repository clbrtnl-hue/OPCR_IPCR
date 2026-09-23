<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PersonName;
use Tests\PmsTestCase;

class PersonNameTest extends PmsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeOrganization();
    }

    public static function names(): array
    {
        return [
            'title and credentials' => ['DR. AMABELLE D. PACANA, CPA', 'DR.', 'AMABELLE', 'D.', 'PACANA', null, 'CPA'],
            'middle initial'        => ['Wenie Rose D. Canay', null, 'Wenie Rose', 'D.', 'Canay', null, null],
            'two-word given name'   => ['Novelyn Joy Daculan', null, 'Novelyn Joy', null, 'Daculan', null, null],
            'name extension'        => ['Juan Cruz Jr.', null, 'Juan', null, 'Cruz', 'Jr.', null],
            'one word'              => ['Madonna', null, 'Madonna', null, null, null, null],
        ];
    }

    /** @dataProvider names */
    public function test_a_written_name_splits_and_reads_back_the_same(
        string $name, ?string $prefix, ?string $first, ?string $initial,
        ?string $last, ?string $suffix, ?string $credentials
    ): void {
        $parts = PersonName::parse($name);

        $this->assertSame($prefix, $parts['prefix']);
        $this->assertSame($first, $parts['first_name']);
        $this->assertSame($initial, $parts['middle_initial']);
        $this->assertSame($last, $parts['last_name']);
        $this->assertSame($suffix, $parts['suffix']);
        $this->assertSame($credentials, $parts['credentials']);

        $this->assertSame($name, PersonName::compose($parts));
    }

    public function test_the_parts_compose_the_name_every_signature_reads(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'prefix'         => 'Dr.',
            'first_name'     => 'Amabelle',
            'middle_initial' => 'D',
            'last_name'      => 'Pacana',
            'credentials'    => 'CPA',
            'email'          => 'amabelle@occ.edu.ph',
            'password'       => 'password123',
            'role'           => 'employee',
        ])->assertStatus(201);

        $user = User::where('email', 'amabelle@occ.edu.ph')->first();

        $this->assertSame('Dr. Amabelle D. Pacana, CPA', $user->name);
        $this->assertSame('D.', $user->middle_initial, 'A bare letter is stored as an initial.');
    }

    public function test_editing_a_part_rewrites_the_name(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'first_name' => 'Juan', 'last_name' => 'Cruz',
            'email' => 'juan@occ.edu.ph', 'password' => 'password123', 'role' => 'employee',
        ])->assertStatus(201);

        $user = User::where('email', 'juan@occ.edu.ph')->first();
        $this->assertSame('Juan Cruz', $user->name);

        $this->postJson('/api/users', [
            'id' => $user->id,
            'first_name' => 'Juan', 'middle_initial' => 'P', 'last_name' => 'Cruz', 'suffix' => 'Jr.',
            'email' => 'juan@occ.edu.ph', 'role' => 'employee',
        ])->assertOk();

        $this->assertSame('Juan P. Cruz Jr.', $user->fresh()->name);
    }

    public function test_the_first_and_last_name_are_required(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'middle_initial' => 'D',
            'email' => 'nobody@occ.edu.ph', 'password' => 'password123', 'role' => 'employee',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_an_account_saved_without_parts_keeps_the_name_it_was_given(): void
    {
        $user = User::factory()->create(['name' => 'System Administrator', 'role' => 'admin']);

        $this->assertSame('System Administrator', $user->fresh()->name);
    }

    public function test_lists_are_ordered_by_surname(): void
    {
        foreach ([
            ['first_name' => 'Novelyn Joy', 'last_name' => 'Daculan'],
            ['first_name' => 'Bernadeth', 'middle_initial' => 'T.', 'last_name' => 'Nacua'],
            ['first_name' => 'Amabelle', 'last_name' => 'Pacana'],
            ['first_name' => 'Ruben', 'last_name' => 'Madriaga'],
        ] as $parts) {
            User::factory()->create($parts + ['role' => 'employee', 'status' => 'active']);
        }

        $this->actingAsRole('admin');

        $surnames = collect($this->getJson('/api/users')->assertOk()->json())
            ->pluck('last_name')
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['Daculan', 'Madriaga', 'Nacua', 'Pacana'], $surnames);
    }

    public function test_the_assignable_and_mentionable_lists_read_as_a_registry(): void
    {
        $unit = $this->makeUnit();

        foreach ([
            ['first_name' => 'Wenie Rose', 'last_name' => 'Canay'],
            ['first_name' => 'Amabelle', 'last_name' => 'Pacana'],
        ] as $parts) {
            User::factory()->create($parts + ['role' => 'employee', 'org_unit_id' => $unit->id]);
        }

        $this->actingAsRole('president');

        $names = collect($this->getJson('/api/assignable-users')->assertOk()->json())
            ->pluck('name')
            ->filter(fn ($name) => str_contains($name, 'Canay') || str_contains($name, 'Pacana'))
            ->values()
            ->all();

        $this->assertSame(['Wenie Rose Canay', 'Amabelle Pacana'], $names);
    }
}
