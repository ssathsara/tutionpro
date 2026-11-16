<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    public function test_admin_only_sees_students_of_own_institute(): void
    {
        [$a, $adminA] = $this->makeInstitute('Alpha');
        [$b] = $this->makeInstitute('Beta');
        $this->makeStudent($a);
        $this->makeStudent($b);
        $this->makeStudent($b);

        $this->actingAsUser($adminA)->getJson('/api/students')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_cannot_access_other_tenant_records_by_id(): void
    {
        [$a, $adminA] = $this->makeInstitute('Alpha');
        [$b] = $this->makeInstitute('Beta');
        $foreignStudent = $this->makeStudent($b);
        $foreignBatch = $this->makeBatch($b);

        $this->actingAsUser($adminA);
        $this->getJson("/api/students/{$foreignStudent->id}")->assertNotFound();
        $this->putJson("/api/batches/{$foreignBatch->id}", ['name' => 'Hacked', 'medium' => 'Sinhala', 'mode' => 'physical', 'monthly_fee' => 1])
            ->assertNotFound();
        $this->deleteJson("/api/students/{$foreignStudent->id}")->assertNotFound();
        $this->assertDatabaseHas('students', ['id' => $foreignStudent->id]);
    }

    public function test_cannot_reference_other_tenant_ids_in_payloads(): void
    {
        [$a, $adminA] = $this->makeInstitute('Alpha');
        [$b] = $this->makeInstitute('Beta');
        $foreignBatch = $this->makeBatch($b);

        $this->actingAsUser($adminA)->postJson('/api/students', [
            'name' => 'Kasun', 'email' => 'k@x.lk', 'batch_ids' => [$foreignBatch->id],
        ])->assertStatus(422)->assertJsonValidationErrors('batch_ids.0');
    }

    public function test_created_records_are_stamped_with_tenant(): void
    {
        [$a, $adminA] = $this->makeInstitute('Alpha');
        $this->actingAsUser($adminA)->postJson('/api/subjects', ['name' => 'Physics'])->assertCreated();
        $this->assertDatabaseHas('subjects', ['name' => 'Physics', 'institute_id' => $a->id]);
    }

    public function test_super_admin_manages_institutes_but_not_tenant_data(): void
    {
        [$a] = $this->makeInstitute('Alpha');
        $super = User::create(['name' => 'Root', 'email' => 'root@tp.lk', 'password' => 'password1', 'role' => User::SUPER_ADMIN]);

        $this->actingAsUser($super);
        $this->getJson('/api/institutes')->assertOk()->assertJsonPath('total', 1);
        $this->putJson("/api/institutes/{$a->id}", ['is_active' => false])->assertOk();
        $this->getJson('/api/students')->assertForbidden();
    }

    public function test_tenant_admin_cannot_manage_institutes(): void
    {
        [, $admin] = $this->makeInstitute();
        $this->actingAsUser($admin)->getJson('/api/institutes')->assertForbidden();
    }
}
