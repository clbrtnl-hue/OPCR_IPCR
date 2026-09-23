<?php

namespace Tests\Feature;

use Tests\PmsTestCase;

class RoleGatingTest extends PmsTestCase
{
    public static function adminOnlyRoutes(): array
    {
        return [
            'user list'   => ['get', '/api/users'],
            'audit trail' => ['get', '/api/audit-logs'],
        ];
    }

    /**
     * @dataProvider adminOnlyRoutes
     */
    public function test_admin_routes_reject_an_employee(string $method, string $url): void
    {
        $this->actingAsRole('employee');

        $this->json($method, $url)->assertStatus(403);
    }

    /**
     * @dataProvider adminOnlyRoutes
     */
    public function test_admin_routes_reject_a_guest(string $method, string $url): void
    {
        $this->json($method, $url)->assertStatus(401);
    }

    /**
     * @dataProvider adminOnlyRoutes
     */
    public function test_admin_passes_every_gate(string $method, string $url): void
    {
        $this->actingAsRole('admin');

        $this->json($method, $url)->assertOk();
    }

    public function test_only_qa_can_post_ratings(): void
    {
        $this->actingAsRole('program_head');

        $this->postJson('/api/pcr-ratings', [])->assertStatus(403);
    }

    public function test_reports_are_closed_to_an_employee_but_open_to_the_president(): void
    {
        $year = $this->makeSchoolYear();

        $this->actingAsRole('employee');
        $this->getJson('/api/reports/summary?school_year_id=' . $year->id)->assertStatus(403);

        $this->actingAsRole('president');
        $this->getJson('/api/reports/summary?school_year_id=' . $year->id)->assertOk();
    }

    public function test_a_second_active_president_is_refused(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/users', [
            'first_name' => 'First',
            'last_name'  => 'President',
            'email'    => 'first.president@occ.edu.ph',
            'password' => 'password123',
            'role'     => 'president',
        ])->assertStatus(201);

        $this->postJson('/api/users', [
            'first_name' => 'Second',
            'last_name'  => 'President',
            'email'    => 'second.president@occ.edu.ph',
            'password' => 'password123',
            'role'     => 'president',
        ])->assertStatus(409);
    }
}
