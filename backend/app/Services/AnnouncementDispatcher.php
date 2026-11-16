<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Batch;
use App\Models\Student;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Sends an announcement to its audience (in-app + optional SMS) exactly once.
 * Called immediately for announcements published now, and by the
 * announcements:send-scheduled command for ones scheduled in the future.
 */
class AnnouncementDispatcher
{
    public function __construct(private Notifier $notifier) {}

    public function isDue(Announcement $a): bool
    {
        return ! $a->published_at || $a->published_at->lte(now());
    }

    /** Returns the number of recipients notified (0 if not yet due or already sent). */
    public function dispatch(Announcement $a): int
    {
        if ($a->notified_at || ! $this->isDue($a)) {
            return 0;
        }

        $previous = app(Tenant::class)->id();
        app(Tenant::class)->set($a->institute_id);
        try {
            $recipients = $this->recipients($a);
            $this->notifier->notify($recipients, 'announcement', $a->title,
                mb_strimwidth($a->body, 0, 140, '…'), '/announcements', (bool) $a->send_sms);
            $a->forceFill(['notified_at' => now()])->save();

            return $recipients->count();
        } finally {
            app(Tenant::class)->set($previous);
        }
    }

    public function recipients(Announcement $a): Collection
    {
        $batch = $a->batch_id ? Batch::find($a->batch_id) : null;
        $students = $batch
            ? $batch->activeStudents()->with('user', 'guardians')->get()
            : Student::where('status', 'active')->with('user', 'guardians')->get();

        $users = collect();
        if (in_array($a->audience, ['all', 'students'])) {
            $users = $users->merge($students->pluck('user'));
        }
        if (in_array($a->audience, ['all', 'parents'])) {
            $users = $users->merge($students->flatMap->guardians);
        }
        if (in_array($a->audience, ['all', 'teachers'])) {
            $teachers = $batch
                ? User::whereHas('teacher', fn ($q) => $q->where('id', $batch->teacher_id))->get()
                : User::where('role', User::TEACHER)->get();
            $users = $users->merge($teachers);
        }

        return $users->filter()->unique('id')->reject(fn ($u) => $u->id === $a->created_by)->values();
    }
}
