<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\TimetableSlot;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimetableController extends Controller
{
    public function index(Request $request)
    {
        $ids = $this->user()->visibleBatchIds();

        return TimetableSlot::query()
            ->with(['batch:id,name,teacher_id,subject_id,room,medium,mode', 'batch.teacher.user:id,name', 'batch.subject:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->orderBy('day_of_week')->orderBy('start_time')
            ->get()
            ->map(fn (TimetableSlot $s) => array_merge($s->toArray(), [
                'batch_name' => $s->batch?->name,
                'teacher_name' => $s->batch?->teacher?->user?->name,
                'subject_name' => $s->batch?->subject?->name,
            ]));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $this->assertNoConflict($data);

        return response()->json(TimetableSlot::create($data), 201);
    }

    public function update(Request $request, TimetableSlot $timetableSlot)
    {
        $data = $this->validateData($request);
        $this->assertNoConflict($data, $timetableSlot->id);
        $timetableSlot->update($data);

        return $timetableSlot;
    }

    public function destroy(TimetableSlot $timetableSlot)
    {
        $timetableSlot->delete();

        return response()->noContent();
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'batch_id' => ['required', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            'end_time' => ['required', 'date_format:H:i,H:i:s', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:100'],
        ]);
        // Normalise to HH:MM:SS so string comparisons against stored TIME values are exact.
        $data['start_time'] = substr($data['start_time'], 0, 5).':00';
        $data['end_time'] = substr($data['end_time'], 0, 5).':00';

        return $data;
    }

    /** Prevent double-booking a room or a teacher. */
    private function assertNoConflict(array $data, ?int $ignoreId = null): void
    {
        $batch = Batch::findOrFail($data['batch_id']);
        $overlapping = TimetableSlot::with('batch:id,name,teacher_id')
            ->where('day_of_week', $data['day_of_week'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($overlapping as $slot) {
            if ($slot->batch_id === $batch->id) {
                abort(422, 'This batch already has a class at that time.');
            }
            if (! empty($data['room']) && $slot->room && strcasecmp($slot->room, $data['room']) === 0) {
                abort(422, "Room {$slot->room} is already booked by {$slot->batch->name} at that time.");
            }
            if ($batch->teacher_id && $slot->batch?->teacher_id === $batch->teacher_id) {
                abort(422, "The teacher already has {$slot->batch->name} at that time.");
            }
        }
    }
}
