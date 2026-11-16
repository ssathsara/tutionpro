<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Exam;
use App\Models\FeeInvoice;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Thread;
use App\Models\User;
use App\Services\Analytics;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** One call per page load: the data each role sees on their home screen. */
class DashboardController extends Controller
{
    public function __invoke(Analytics $analytics)
    {
        $user = $this->user();
        if ($user->role === User::SUPER_ADMIN) {
            return ['role' => $user->role, 'platform' => app(InstituteController::class)->stats()];
        }

        $ids = $user->visibleBatchIds();
        $scopeBatches = fn ($q) => $ids === null ? $q : $q->whereIn('batch_id', $ids);

        $base = [
            'role' => $user->role,
            'unread_notifications' => AppNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
            'today_sessions' => ClassSession::with('batch:id,name')->tap($scopeBatches)->whereDate('date', today())
                ->where('status', '!=', 'cancelled')->orderBy('start_time')->get()
                ->map(fn ($s) => array_merge(Arr::only($s->toArray(), ['id', 'title', 'date', 'start_time', 'end_time', 'type', 'status']), ['batch_name' => $s->batch?->name])),
            'announcements' => Announcement::with('batch:id,name')
                ->where(fn ($q) => $ids === null ? $q : $q->whereNull('batch_id')->orWhereIn('batch_id', $ids))
                ->whereIn('audience', match ($user->role) {
                    User::STUDENT => ['all', 'students'], User::PARENT => ['all', 'parents'], default => ['all', 'students', 'parents', 'teachers'],
                })
                // Scheduled announcements stay hidden from the home screen until their publish time.
                ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->orderByDesc('is_pinned')->latest('published_at')->limit(5)->get(['id', 'title', 'body', 'batch_id', 'is_pinned', 'published_at']),
        ];

        return array_merge($base, match ($user->role) {
            User::ADMIN => [
                'stats' => $analytics->overview($user),
                'recent_payments' => Payment::with('student.user:id,name')->latest('id')->limit(6)->get()
                    ->map(fn ($p) => array_merge(Arr::only($p->toArray(), ['id', 'receipt_no', 'amount', 'method', 'paid_at']), ['student_name' => $p->student?->user?->name])),
                'revenue' => $analytics->revenue($user, 6),
            ],
            User::TEACHER => [
                'stats' => $analytics->overview($user),
                'batches' => Batch::visibleTo($user)->withCount('activeStudents as students_count')->get(['id', 'name', 'grade', 'medium']),
                'open_doubts' => Thread::where('type', 'doubt')->where('status', 'open')->tap($scopeBatches)->with('user:id,name', 'batch:id,name')
                    ->latest()->limit(5)->get(),
                'upcoming_exams' => Exam::tap($scopeBatches)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->with('batch:id,name')->orderBy('starts_at')->limit(5)->get(['id', 'title', 'batch_id', 'starts_at', 'ends_at', 'is_published']),
            ],
            User::STUDENT, User::PARENT => $this->learnerData($user, $ids ?? [], $scopeBatches),
            default => [],
        });
    }

    private function learnerData(User $user, array $batchIds, \Closure $scopeBatches): array
    {
        $studentIds = $user->visibleStudentIds();
        $att = DB::table('attendances')->whereIn('student_id', $studentIds)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $attTotal = $att->sum();

        $lessonIds = Lesson::whereIn('batch_id', $batchIds)->where('is_published', true)->pluck('id');
        $completed = DB::table('lesson_progress')->whereIn('lesson_id', $lessonIds)->whereIn('student_id', $studentIds)->whereNotNull('completed_at')->count();

        return [
            'stats' => [
                'batches' => count($batchIds),
                'attendance_rate' => $attTotal ? round((($att['present'] ?? 0) + ($att['late'] ?? 0)) / $attTotal * 100, 1) : null,
                'outstanding' => round((float) FeeInvoice::whereIn('student_id', $studentIds)->whereIn('status', ['unpaid', 'partial'])
                    ->sum(DB::raw('amount - discount - paid_amount')), 2),
                'lessons_completed' => $completed,
                // Each child only counts lessons from their own batches (not every child's batches).
                'lessons_total' => DB::table('batch_student')
                    ->join('lessons', 'lessons.batch_id', '=', 'batch_student.batch_id')
                    ->whereIn('batch_student.student_id', $studentIds)->where('batch_student.status', 'active')
                    ->where('lessons.is_published', true)->count(),
            ],
            'upcoming_sessions' => ClassSession::with('batch:id,name')->tap($scopeBatches)
                ->whereBetween('date', [today(), today()->addDays(7)])->where('status', 'scheduled')
                ->orderBy('date')->orderBy('start_time')->limit(8)->get()
                ->map(fn ($s) => array_merge(Arr::only($s->toArray(), ['id', 'title', 'date', 'start_time', 'type']), ['batch_name' => $s->batch?->name])),
            'open_exams' => Exam::tap($scopeBatches)->where('is_published', true)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->with('batch:id,name')->orderBy('starts_at')->limit(5)->get(['id', 'title', 'batch_id', 'starts_at', 'ends_at', 'duration_minutes']),
            'pending_invoices' => FeeInvoice::whereIn('student_id', $studentIds)->whereIn('status', ['unpaid', 'partial'])
                ->with('student.user:id,name')->orderBy('due_date')->limit(5)->get(),
            'children' => $user->role === User::PARENT ? app(ParentController::class)->children() : null,
        ];
    }
}
