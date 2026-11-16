<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\FeeInvoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reporting queries. All totals are computed by the database (GROUP BY), so cost grows
 * with the number of groups (months, weeks, batches), not with the number of rows.
 * Results are cached briefly per institute (and per teacher scope).
 */
class Analytics
{
    /** Seconds report results are cached. Dashboards show "updated every few minutes". */
    public const TTL = 300;

    public function overview(User $user): array
    {
        return $this->cached('overview', $user, function () use ($user) {
            $ids = $user->visibleBatchIds();
            $studentIds = $user->visibleStudentIds();
            $batches = Batch::visibleTo($user);

            $att = DB::table('attendances')
                ->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->where('attendances.institute_id', $user->institute_id)
                ->when($ids !== null, fn ($q) => $q->whereIn('class_sessions.batch_id', $ids))
                ->whereDate('class_sessions.date', '>=', today()->subDays(30))
                ->selectRaw("count(*) as total, sum(case when attendances.status in ('present','late') then 1 else 0 end) as present")
                ->first();

            $avgExam = DB::table('exam_attempts')->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                ->where('exam_attempts.institute_id', $user->institute_id)
                ->where('exam_attempts.status', 'submitted')
                ->when($ids !== null, fn ($q) => $q->whereIn('exams.batch_id', $ids))
                ->where('exam_attempts.submitted_at', '>=', now()->subDays(90))
                ->avg('exam_attempts.percentage');

            $data = [
                'students' => $studentIds === null ? Student::where('status', 'active')->count() : count($studentIds),
                'batches' => (clone $batches)->count(),
                'active_batches' => (clone $batches)->where('is_active', true)->count(),
                'attendance_rate_30d' => $att->total ? round($att->present / $att->total * 100, 1) : null,
                'average_exam_score_90d' => $avgExam !== null ? round((float) $avgExam, 1) : null,
                'sessions_this_week' => DB::table('class_sessions')->where('institute_id', $user->institute_id)
                    ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
                    ->whereBetween('date', [today()->startOfWeek()->toDateString(), today()->endOfWeek()->toDateString()])->count(),
            ];

            if ($user->isAdmin()) {
                $data['teachers'] = Teacher::count();
                $data['revenue_this_month'] = (float) Payment::where('status', 'completed')->where('paid_at', '>=', now()->startOfMonth())->sum('amount');
                $data['outstanding'] = round((float) FeeInvoice::whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - discount - paid_amount')), 2);
            }

            return $data;
        });
    }

    public function revenue(User $user, int $months = 12): array
    {
        $months = max(1, min(24, $months));

        return $this->cached("revenue:$months", $user, function () use ($months) {
            $start = now()->startOfMonth()->subMonths($months - 1);
            $month = $this->monthExpr('paid_at');

            $collected = Payment::where('status', 'completed')->where('paid_at', '>=', $start)
                ->selectRaw("$month as ym, sum(amount) as total")->groupByRaw($month)->pluck('total', 'ym');
            $invoiced = FeeInvoice::where('status', '!=', 'void')->where('period', '>=', $start->format('Y-m'))
                ->selectRaw('period, sum(amount - discount) as total')->groupBy('period')->pluck('total', 'period');

            $out = [];
            for ($m = $start->copy(); $m->lte(now()); $m->addMonth()) {
                $k = $m->format('Y-m');
                $out[] = ['period' => $k, 'label' => $m->format('M Y'), 'collected' => round((float) ($collected[$k] ?? 0), 2), 'invoiced' => round((float) ($invoiced[$k] ?? 0), 2)];
            }

            $byMethod = Payment::where('status', 'completed')->where('paid_at', '>=', $start)
                ->selectRaw('method, sum(amount) as total')->groupBy('method')->pluck('total', 'method')->map(fn ($v) => round((float) $v, 2));

            return ['months' => $out, 'by_method' => $byMethod];
        });
    }

    public function attendance(User $user): array
    {
        return $this->cached('attendance', $user, function () use ($user) {
            $ids = $user->visibleBatchIds();
            $from = today()->subWeeks(7)->startOfWeek();
            $base = fn () => DB::table('attendances')
                ->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->where('attendances.institute_id', $user->institute_id)
                ->when($ids !== null, fn ($q) => $q->whereIn('class_sessions.batch_id', $ids))
                ->whereDate('class_sessions.date', '>=', $from);
            $presentSql = "sum(case when attendances.status in ('present','late') then 1 else 0 end)";

            $week = $this->weekStartExpr('class_sessions.date');
            $weekly = $base()->selectRaw("$week as wk, count(*) as total, $presentSql as present")->groupByRaw($week)->get()
                ->keyBy(fn ($r) => substr((string) $r->wk, 0, 10));

            $out = [];
            for ($w = $from->copy(); $w->lte(today()); $w->addWeek()) {
                $r = $weekly[$w->toDateString()] ?? null;
                $out[] = ['week' => $w->format('d M'), 'present' => (int) ($r->present ?? 0), 'total' => (int) ($r->total ?? 0),
                    'rate' => $r && $r->total ? round($r->present / $r->total * 100, 1) : null];
            }

            $names = Batch::visibleTo($user)->pluck('name', 'id');
            $byBatch = $base()->selectRaw("class_sessions.batch_id, count(*) as total, $presentSql as present")
                ->groupBy('class_sessions.batch_id')->get()
                ->map(fn ($r) => ['batch_id' => $r->batch_id, 'batch_name' => $names[$r->batch_id] ?? '—',
                    'rate' => round($r->present / max(1, $r->total) * 100, 1), 'records' => (int) $r->total])
                ->sortBy('rate')->values();

            return ['weekly' => $out, 'by_batch' => $byBatch];
        });
    }

    public function exams(User $user): array
    {
        return $this->cached('exams', $user, function () use ($user) {
            $ids = $user->visibleBatchIds();
            $rows = DB::table('exam_attempts')
                ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                ->leftJoin('batches', 'batches.id', '=', 'exams.batch_id')
                ->where('exam_attempts.institute_id', $user->institute_id)
                ->where('exam_attempts.status', 'submitted')
                ->when($ids !== null, fn ($q) => $q->whereIn('exams.batch_id', $ids))
                ->groupBy('exams.id', 'exams.title', 'batches.name')
                ->orderByDesc('exams.id')->limit(12)
                ->selectRaw('exams.id, exams.title, batches.name as batch_name, count(*) as attempts, avg(exam_attempts.percentage) as average,
                    sum(case when exam_attempts.percentage >= exams.pass_percentage then 1 else 0 end) as passed')
                ->get();

            return ['exams' => $rows->map(fn ($r) => [
                'id' => $r->id, 'title' => $r->title, 'batch_name' => $r->batch_name, 'attempts' => (int) $r->attempts,
                'average' => round((float) $r->average, 1), 'pass_rate' => round($r->passed / max(1, $r->attempts) * 100, 1),
            ])->reverse()->values()];
        });
    }

    /** Students needing attention. Returns the most serious LIMIT cases plus the total count. */
    public function atRisk(User $user, int $limit = 200): array
    {
        return $this->cached("at-risk:$limit", $user, function () use ($user, $limit) {
            $iid = $user->institute_id;
            // Teachers: only attendance and exams in their own batches (filtered by batch, which uses
            // the class_sessions(batch_id, date) index, instead of a long student-id list).
            $batchIds = $user->visibleBatchIds();

            // Per-student aggregates (one row per student, computed by the database).
            $att = DB::table('attendances')
                ->join('class_sessions', 'class_sessions.id', '=', 'attendances.class_session_id')
                ->where('attendances.institute_id', $iid)
                ->when($batchIds !== null, fn ($q) => $q->whereIn('class_sessions.batch_id', $batchIds))
                ->whereDate('class_sessions.date', '>=', today()->subDays(60))
                ->selectRaw("attendances.student_id, count(*) as total, sum(case when attendances.status in ('present','late') then 1 else 0 end) as present")
                ->groupBy('attendances.student_id')->get()->keyBy('student_id');

            $exam = DB::table('exam_attempts')->where('exam_attempts.institute_id', $iid)->where('exam_attempts.status', 'submitted')
                ->where('exam_attempts.submitted_at', '>=', now()->subDays(90))
                ->when($batchIds !== null, fn ($q) => $q->whereIn('exam_attempts.exam_id', DB::table('exams')->whereIn('batch_id', $batchIds)->select('id')))
                ->selectRaw('exam_attempts.student_id, avg(exam_attempts.percentage) as avg_pct')->groupBy('exam_attempts.student_id')
                ->pluck('avg_pct', 'student_id');

            $overdue = $user->isAdmin()
                ? DB::table('fee_invoices')->where('institute_id', $iid)->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())
                    ->selectRaw('student_id, count(*) as c')->groupBy('student_id')->pluck('c', 'student_id')
                : collect();

            $flags = [];
            foreach ($att->keys()->merge($exam->keys())->merge($overdue->keys())->unique() as $sid) {
                $reasons = [];
                $a = $att[$sid] ?? null;
                $rate = $a && $a->total ? $a->present / $a->total * 100 : null;
                if ($rate !== null && $a->total >= 3 && $rate < 75) {
                    $reasons[] = sprintf('Attendance %.0f%%', $rate);
                }
                $avg = isset($exam[$sid]) ? (float) $exam[$sid] : null;
                if ($avg !== null && $avg < 40) {
                    $reasons[] = sprintf('Exam average %.0f%%', $avg);
                }
                if (($overdue[$sid] ?? 0) > 0) {
                    $reasons[] = $overdue[$sid].' overdue invoice(s)';
                }
                if ($reasons) {
                    $flags[$sid] = ['rate' => $rate, 'avg' => $avg, 'reasons' => $reasons];
                }
            }

            uasort($flags, fn ($x, $y) => count($y['reasons']) <=> count($x['reasons']) ?: ($x['rate'] ?? 100) <=> ($y['rate'] ?? 100));
            $top = array_slice($flags, 0, $limit, true);

            // Only now load names and batches, and only for the students being shown.
            $students = Student::with('user:id,name,phone', 'batches:id,name')->where('status', 'active')
                ->whereIn('id', array_keys($top))->get()->keyBy('id');

            $list = [];
            foreach ($top as $sid => $f) {
                $s = $students[$sid] ?? null;
                if (! $s) {
                    continue; // inactive / graduated
                }
                $list[] = [
                    'student_id' => $sid, 'name' => $s->user?->name, 'phone' => $s->user?->phone, 'admission_no' => $s->admission_no,
                    'batches' => $s->batches->pluck('name')->implode(', '),
                    'attendance_rate' => $f['rate'] !== null ? round($f['rate'], 1) : null,
                    'exam_average' => $f['avg'] !== null ? round($f['avg'], 1) : null,
                    'reasons' => $f['reasons'], 'severity' => count($f['reasons']),
                ];
            }

            return ['total' => count($flags), 'students' => $list];
        });
    }

    private function cached(string $name, User $user, \Closure $compute): mixed
    {
        $scope = $user->isAdmin() ? 'all' : 'u'.$user->id;

        return Cache::remember("analytics:{$user->institute_id}:$scope:$name", self::TTL, $compute);
    }

    private function monthExpr(string $col): string
    {
        return DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')";
    }

    /** Monday of the week containing $col, as YYYY-MM-DD. */
    private function weekStartExpr(string $col): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "date($col, '-' || ((cast(strftime('%w', $col) as integer) + 6) % 7) || ' days')"
            : "DATE_SUB($col, INTERVAL WEEKDAY($col) DAY)";
    }
}
