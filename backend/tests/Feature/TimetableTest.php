<?php

namespace Tests\Feature;

use Tests\TestCase;

class TimetableTest extends TestCase
{
    public function test_room_and_teacher_double_booking_is_rejected(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $teacher = $this->makeTeacher($inst);
        $b1 = $this->makeBatch($inst, $teacher);
        $b2 = $this->makeBatch($inst, $teacher);
        $b3 = $this->makeBatch($inst);

        $this->actingAsUser($admin);
        $this->postJson('/api/timetable', ['batch_id' => $b1->id, 'day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '10:00', 'room' => 'Hall A'])
            ->assertCreated();
        // Same teacher, overlapping
        $this->postJson('/api/timetable', ['batch_id' => $b2->id, 'day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '11:00'])
            ->assertStatus(422);
        // Same room, overlapping
        $this->postJson('/api/timetable', ['batch_id' => $b3->id, 'day_of_week' => 6, 'start_time' => '09:30', 'end_time' => '10:30', 'room' => 'hall a'])
            ->assertStatus(422);
        // Back-to-back is fine
        $this->postJson('/api/timetable', ['batch_id' => $b2->id, 'day_of_week' => 6, 'start_time' => '10:00', 'end_time' => '12:00', 'room' => 'Hall A'])
            ->assertCreated();
    }
}
