<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Exam;
use App\Models\Lesson;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks students in a batch by a composite score (max 1000 points):
 *   60% exam performance (best attempt per exam, missed exams count as 0)
 *   30% attendance rate
 *   10% lesson completion
 */
class Leaderboard
{
    public const EXAM_WEIGHT = 6;
    public const ATTENDANCE_WEIGHT = 3;
    public const LESSON_WEIGHT = 1;

    public function forBatch(Batch $batch, ?Carbon $since = null): Collection
    {
        $students = $batch->activeStudents()->with('user:id,name')->get();
        if ($students->isEmpty()) {
            return collect();
        }
        $sids = $students->pluck('id');

        // Exams: best percentage per student per exam.
        $examIds = Exam::where('batch_id', $batch->id)->where('is_published', true)
            ->whereHas('attempts', fn ($q) => $q->where('status', 'submitted')->when($since, fn ($w) => $w->where('submitted_at', '>=', $since)))
            ->pluck('id');
        $best = DB::table('exam_attempts')->whereIn('exam_id', $examIds)->whereIn('student_id', $sids)
            ->where('status', 'submitted')->when($since, fn ($q) => $q->where('submitted_at', '>=', $since))
            ->selectRaw('student_id, exam_id, max(percentage) as best')->groupBy('student_id', 'exam_id')->get()
            ->groupBy('student_id');

        // Attendance over completed sessions.
        $sessionIds = ClassSession::where('batch_id', $batch->id)->where('status', 'completed')
            ->when($since, fn ($q) => $q->whereDate('date', '>=', $since))->pluck('id');
        // Filter by session only (uses the class_session_id index); adding a student_id list made
        // MySQL scan every attendance row of those students across all their batches (~1s at 5,000 students).
        $attended = DB::table('attendances')->whereIn('class_session_id', $sessionIds)
            ->whereIn('status', ['present', 'late'])->selectRaw('student_id, count(*) as c')->groupBy('student_id')->pluck('c', 'student_id');

        // Lessons.
        $lessonIds = Lesson::where('batch_id', $batch->id)->where('is_published', true)->pluck('id');
        $lessons = DB::table('lesson_progress')->whereIn('lesson_id', $lessonIds)->whereIn('student_id', $sids)
            ->whereNotNull('completed_at')->selectRaw('student_id, count(*) as c')->groupBy('student_id')->pluck('c', 'student_id');

        $examCount = $examIds->count();
        $sessionCount = $sessionIds->count();
        $lessonCount = $lessonIds->count();

        $rows = $students->map(function ($s) use ($best, $attended, $lessons, $examCount, $sessionCount, $lessonCount) {
            $bestScores = collect($best[$s->id] ?? [])->pluck('best')->map(fn ($v) => (float) $v);
            $examAvg = $examCount ? $bestScores->sum() / $examCount : 0;
            $attRate = $sessionCount ? ($attended[$s->id] ?? 0) / $sessionCount * 100 : 0;
            $lessonRate = $lessonCount ? ($lessons[$s->id] ?? 0) / $lessonCount * 100 : 0;

            return [
                'student_id' => $s->id,
                'name' => $s->user?->name,
                'admission_no' => $s->admission_no,
                'exams_taken' => $bestScores->count(),
                'exam_average' => round($examAvg, 1),
                'attendance_rate' => round($attRate, 1),
                'lesson_completion' => round($lessonRate, 1),
                'points' => (int) round($examAvg * self::EXAM_WEIGHT + $attRate * self::ATTENDANCE_WEIGHT + $lessonRate * self::LESSON_WEIGHT),
            ];
        })->sortByDesc('points')->values();

        // Dense ranking with ties sharing a rank.
        $rank = 0;
        $prev = null;

        return $rows->map(function ($r, $i) use (&$rank, &$prev) {
            if ($r['points'] !== $prev) {
                $rank = $i + 1;
                $prev = $r['points'];
            }

            return array_merge(['rank' => $rank], $r);
        });
    }
}
