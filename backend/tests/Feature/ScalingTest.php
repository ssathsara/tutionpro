<?php

namespace Tests\Feature;

use App\Jobs\GenerateMonthlyInvoices;
use App\Jobs\SendSmsMessages;
use App\Models\ClassSession;
use App\Models\FeeInvoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\DocumentNumber;
use App\Services\Sms\SmsGateway;
use App\Support\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScalingTest extends TestCase
{
    public function test_document_numbers_continue_past_99999_without_duplicates(): void
    {
        [$inst] = $this->makeInstitute();
        app(Tenant::class)->set($inst->id);
        $student = $this->makeStudent($inst);
        $prefix = 'INV-'.now()->format('Ym');
        FeeInvoice::create(['institute_id' => $inst->id, 'student_id' => $student->id, 'invoice_no' => "$prefix-99998", 'amount' => 1]);

        $numbers = app(DocumentNumber::class)->next('INV', FeeInvoice::class, 'invoice_no', 3);
        $this->assertSame(["$prefix-99999", "$prefix-100000", "$prefix-100001"], $numbers);
        $this->assertSame("$prefix-100002", app(DocumentNumber::class)->one('INV', FeeInvoice::class, 'invoice_no'));
    }

    public function test_numbers_are_separate_per_institute(): void
    {
        [$a] = $this->makeInstitute('A');
        [$b] = $this->makeInstitute('B');
        $n = app(DocumentNumber::class);
        $this->assertStringEndsWith('-00001', $n->next('RCT', Payment::class, 'receipt_no', 1, $a->id)[0]);
        $this->assertStringEndsWith('-00001', $n->next('RCT', Payment::class, 'receipt_no', 1, $b->id)[0]);
        $this->assertStringEndsWith('-00002', $n->next('RCT', Payment::class, 'receipt_no', 1, $a->id)[0]);
    }

    public function test_announcement_sms_is_queued_in_chunks_and_request_returns_immediately(): void
    {
        Queue::fake();
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        for ($i = 0; $i < 250; $i++) {
            $this->makeStudent($inst, [$batch]);
        }

        $this->actingAsUser($admin)->postJson('/api/announcements', ['title' => 'Holiday', 'body' => 'No class', 'audience' => 'students', 'send_sms' => true])
            ->assertCreated()->assertJsonPath('recipients', 250);

        $this->assertDatabaseCount('app_notifications', 250);   // in-app: immediate
        $this->assertDatabaseCount('sms_logs', 0);              // SMS: not sent during the request
        Queue::assertPushed(SendSmsMessages::class, 3);         // 100 + 100 + 50
    }

    public function test_failed_sms_are_retried_without_resending_delivered_ones(): void
    {
        Queue::fake();
        $gateway = new class extends SmsGateway {
            public array $sent = [];
            public function send(?string $to, string $message): bool
            {
                $this->sent[] = $to;
                return $to !== '0770000002'; // this number fails
            }
        };

        (new SendSmsMessages(null, [['0770000001', 'Hi'], ['0770000002', 'Hi']]))->handle($gateway);

        $this->assertSame(['0770000001', '0770000002'], $gateway->sent);
        Queue::assertPushed(SendSmsMessages::class, fn ($job) => $job->round === 2 && $job->messages === [['0770000002', 'Hi']]);
    }

    public function test_monthly_invoice_generation_runs_as_a_background_job(): void
    {
        Queue::fake();
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $this->makeStudent($inst, [$batch]);
        $this->makeStudent($inst, [$batch]);

        $this->actingAsUser($admin)->postJson('/api/invoices/generate', ['period' => '2026-12'])
            ->assertOk()->assertJsonPath('created', 2)->assertJsonPath('queued', true);
        $this->assertDatabaseCount('fee_invoices', 0);
        Queue::assertPushed(GenerateMonthlyInvoices::class, fn ($j) => $j->period === '2026-12' && $j->instituteId === $inst->id);
    }

    public function test_background_invoice_job_creates_invoices_notifies_and_is_safe_to_rerun(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst, null, ['monthly_fee' => 3000]);
        $parent = $this->makeUser($inst, User::PARENT);
        $s1 = $this->makeStudent($inst, [$batch], $parent);
        $s2 = $this->makeStudent($inst);
        $batch->students()->attach($s2->id, ['status' => 'active', 'discount_percent' => 100]);

        $job = new GenerateMonthlyInvoices($inst->id, '2026-12', 15, null, $admin->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']); // retry / double-run

        $this->assertSame(2, FeeInvoice::withoutGlobalScopes()->count());
        $this->assertSame('paid', FeeInvoice::withoutGlobalScopes()->where('student_id', $s2->id)->value('status'));
        $this->assertSame('2026-12-15', FeeInvoice::withoutGlobalScopes()->where('student_id', $s1->id)->first()->due_date->toDateString());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $s1->user_id, 'type' => 'fee']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $parent->id, 'type' => 'fee']);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $s2->user_id, 'type' => 'fee']); // nothing to pay
        $this->assertDatabaseHas('app_notifications', ['user_id' => $admin->id, 'title' => 'Invoices generated']);
    }

    public function test_reports_aggregate_in_the_database_correctly(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $student = $this->makeStudent($inst, [$batch]);
        app(Tenant::class)->set($inst->id);
        $inv = FeeInvoice::create(['student_id' => $student->id, 'invoice_no' => 'X1', 'amount' => 5000, 'period' => now()->format('Y-m')]);
        foreach ([1000, 1500] as $amt) {
            Payment::create(['fee_invoice_id' => $inv->id, 'student_id' => $student->id, 'receipt_no' => 'R'.$amt, 'amount' => $amt, 'method' => 'cash', 'paid_at' => now(), 'status' => 'completed']);
        }
        // This week: 3 of 4 present
        foreach (['present', 'present', 'late', 'absent'] as $i => $st) {
            $s = ClassSession::create(['batch_id' => $batch->id, 'date' => today()->startOfWeek()->addDays(min($i, today()->dayOfWeekIso - 1)), 'start_time' => sprintf('%02d:00:00', 8 + $i)]);
            $s->attendances()->create(['student_id' => $student->id, 'status' => $st]);
        }
        app(Tenant::class)->set(null);

        $this->actingAsUser($admin);
        $rev = $this->getJson('/api/analytics/revenue')->assertOk()->json('months');
        $this->assertEquals(2500, end($rev)['collected']);
        $this->assertEquals(5000, end($rev)['invoiced']);
        $att = $this->getJson('/api/analytics/attendance')->assertOk()->json();
        $this->assertEquals(75, end($att['weekly'])['rate']);
        $this->assertEquals(75, $att['by_batch'][0]['rate']);
    }

    public function test_at_risk_returns_total_and_limited_list(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $s = $this->makeStudent($inst, [$batch]);
        app(Tenant::class)->set($inst->id);
        foreach (range(1, 4) as $i) {
            $cs = ClassSession::create(['batch_id' => $batch->id, 'date' => today()->subDays($i), 'start_time' => '08:00:00']);
            $cs->attendances()->create(['student_id' => $s->id, 'status' => $i === 1 ? 'present' : 'absent']);
        }
        app(Tenant::class)->set(null);

        $this->actingAsUser($admin)->getJson('/api/analytics/at-risk')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('students.0.student_id', $s->id)->assertJsonPath('students.0.attendance_rate', 25);
    }

    public function test_student_picker_can_search_and_load_by_ids(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst);
        $in = $this->makeStudent($inst, [$batch]);
        $out = $this->makeStudent($inst);

        $this->actingAsUser($admin);
        $this->getJson('/api/students?ids='.$in->id)->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $in->id);
        $this->getJson('/api/students?not_in_batch='.$batch->id)->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $out->id);
    }
}
