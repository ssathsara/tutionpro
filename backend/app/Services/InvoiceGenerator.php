<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\FeeInvoice;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates monthly invoices for every active enrollment using set-based queries and
 * bulk inserts (a few dozen queries for 15,000 invoices instead of ~60,000).
 * Idempotent: enrollments that already have an invoice for the month are skipped,
 * so a retried or repeated run never creates duplicates.
 */
class InvoiceGenerator
{
    public function __construct(private DocumentNumber $numbers) {}

    /** Enrollments that still need an invoice for the period. */
    public function pending(string $period, ?int $batchId = null): array
    {
        $batches = Batch::where('is_active', true)->where('monthly_fee', '>', 0)
            ->when($batchId, fn ($q) => $q->where('id', $batchId))
            ->get(['id', 'name', 'monthly_fee'])->keyBy('id');
        if ($batches->isEmpty()) {
            return [[], $batches];
        }

        $existing = FeeInvoice::where('period', $period)->whereIn('batch_id', $batches->keys())
            ->get(['batch_id', 'student_id'])->mapWithKeys(fn ($i) => [$i->batch_id.'-'.$i->student_id => true]);

        $todo = DB::table('batch_student')
            ->join('students', 'students.id', '=', 'batch_student.student_id')
            ->whereIn('batch_student.batch_id', $batches->keys())
            ->where('batch_student.status', 'active')
            ->where('students.institute_id', app(Tenant::class)->id())
            ->get(['batch_student.batch_id', 'batch_student.student_id', 'batch_student.discount_percent'])
            ->reject(fn ($e) => isset($existing[$e->batch_id.'-'.$e->student_id]))
            ->values()->all();

        return [$todo, $batches];
    }

    public function countPending(string $period, ?int $batchId = null): int
    {
        return count($this->pending($period, $batchId)[0]);
    }

    /** @return int number of invoices created */
    public function generate(string $period, int $dueDay = 10, ?int $batchId = null): int
    {
        [$todo, $batches] = $this->pending($period, $batchId);
        if (! $todo) {
            return 0;
        }

        $iid = app(Tenant::class)->id();
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01');
        $due = $start->copy()->day($dueDay)->toDateString();
        $label = $start->format('F Y');
        $now = now();

        $created = 0;
        foreach (array_chunk($todo, 1000) as $chunk) {
            DB::transaction(function () use ($chunk, $batches, $iid, $period, $due, $label, $now, &$created) {
                $numbers = $this->numbers->next('INV', FeeInvoice::class, 'invoice_no', count($chunk));
                $rows = [];
                foreach ($chunk as $i => $e) {
                    $batch = $batches[$e->batch_id];
                    $discount = round($batch->monthly_fee * ((float) $e->discount_percent) / 100, 2);
                    $rows[] = [
                        'institute_id' => $iid, 'student_id' => $e->student_id, 'batch_id' => $e->batch_id,
                        'invoice_no' => $numbers[$i], 'period' => $period, 'description' => $batch->name.' - '.$label,
                        'amount' => $batch->monthly_fee, 'discount' => $discount, 'paid_amount' => 0,
                        'due_date' => $due, 'status' => $discount >= $batch->monthly_fee ? 'paid' : 'unpaid',
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                DB::table('fee_invoices')->insert($rows);
                $this->notify($rows, $iid, $now);
                $created += count($rows);
            });
        }

        return $created;
    }

    /** In-app notification to each student and their guardians, inserted in bulk. */
    private function notify(array $invoices, int $iid, $now): void
    {
        $studentIds = array_unique(array_column($invoices, 'student_id'));
        $studentUsers = DB::table('students')->whereIn('id', $studentIds)->pluck('user_id', 'id');
        $guardians = DB::table('guardian_student')->whereIn('student_id', $studentIds)->get(['student_id', 'parent_id'])->groupBy('student_id');

        $rows = [];
        foreach ($invoices as $inv) {
            $net = $inv['amount'] - $inv['discount'];
            if ($net <= 0) {
                continue;
            }
            $users = array_filter(array_merge([$studentUsers[$inv['student_id']] ?? null],
                collect($guardians[$inv['student_id']] ?? [])->pluck('parent_id')->all()));
            foreach (array_unique($users) as $uid) {
                $rows[] = [
                    'institute_id' => $iid, 'user_id' => $uid, 'type' => 'fee',
                    'title' => 'New fee invoice '.$inv['invoice_no'],
                    'body' => sprintf('Rs. %s due %s for %s', number_format($net, 2), Carbon::parse($inv['due_date'])->format('d M Y'), $inv['description']),
                    'link' => '/fees', 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table('app_notifications')->insert($c);
        }
    }
}
