<?php

namespace Tests\Feature;

use App\Models\FeeInvoice;
use App\Models\User;
use Tests\TestCase;

class FeeTest extends TestCase
{
    public function test_monthly_invoices_are_generated_with_discounts_and_no_duplicates(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $batch = $this->makeBatch($inst, null, ['monthly_fee' => 3000]);
        $s1 = $this->makeStudent($inst, [$batch]);
        $s2 = $this->makeStudent($inst);
        $batch->students()->attach($s2->id, ['status' => 'active', 'discount_percent' => 50]);

        $this->actingAsUser($admin)->postJson('/api/invoices/generate', ['period' => '2026-11', 'due_day' => 10])
            ->assertOk()->assertJsonPath('created', 2);
        $this->postJson('/api/invoices/generate', ['period' => '2026-11'])->assertJsonPath('created', 0);

        $inv = FeeInvoice::where('student_id', $s2->id)->first();
        $this->assertEquals(1500, $inv->discount);
        $this->assertSame('2026-11-10', $inv->due_date->toDateString());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $s1->user_id, 'type' => 'fee']);
    }

    public function test_partial_and_full_payment_update_status_and_overpayment_is_rejected(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $student = $this->makeStudent($inst);
        $this->actingAsUser($admin);
        $invoiceId = $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount' => 3000, 'discount' => 500])
            ->assertCreated()->json('id');

        $this->postJson("/api/invoices/$invoiceId/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->assertSame('partial', FeeInvoice::find($invoiceId)->status);

        $this->postJson("/api/invoices/$invoiceId/payments", ['amount' => 2000, 'method' => 'cash'])->assertStatus(422);

        $receipt = $this->postJson("/api/invoices/$invoiceId/payments", ['amount' => 1500, 'method' => 'bank_transfer', 'reference' => 'BOC-123'])
            ->assertCreated()->json('receipt_no');
        $inv = FeeInvoice::find($invoiceId);
        $this->assertSame('paid', $inv->status);
        $this->assertEquals(0, $inv->balance);
        $this->assertMatchesRegularExpression('/^RCT-\d{6}-\d{5}$/', $receipt);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.recorded']);
    }

    public function test_learners_only_see_their_own_invoices_and_receipts(): void
    {
        [$inst, $admin] = $this->makeInstitute();
        $parent = $this->makeUser($inst, User::PARENT);
        $child = $this->makeStudent($inst, [], $parent);
        $other = $this->makeStudent($inst);

        $this->actingAsUser($admin);
        $mine = $this->postJson('/api/invoices', ['student_id' => $child->id, 'amount' => 1000])->json('id');
        $theirs = $this->postJson('/api/invoices', ['student_id' => $other->id, 'amount' => 1000])->json('id');
        $theirPayment = $this->postJson("/api/invoices/$theirs/payments", ['amount' => 1000, 'method' => 'cash'])->json('id');

        $this->actingAsUser($parent);
        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine);
        $this->getJson("/api/payments/$theirPayment/receipt")->assertForbidden();
        $this->postJson("/api/invoices/$mine/payments", ['amount' => 1000, 'method' => 'cash'])->assertForbidden();
    }

    public function test_payhere_notification_with_valid_signature_records_payment(): void
    {
        config(['tuitionpro.payhere.merchant_id' => '1211149', 'tuitionpro.payhere.merchant_secret' => 'secret-xyz']);
        [$inst, $admin] = $this->makeInstitute();
        $parent = $this->makeUser($inst, User::PARENT);
        $student = $this->makeStudent($inst, [], $parent);
        $this->actingAsUser($admin);
        $invoiceId = $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount' => 2500])->json('id');

        // Parent starts checkout; hash is generated server side
        $this->actingAsUser($parent);
        $checkout = $this->postJson("/api/invoices/$invoiceId/payhere")->assertOk()->json('fields');
        $this->assertSame('2500.00', $checkout['amount']);
        $this->assertSame(strtoupper(md5('1211149'.$checkout['order_id'].'2500.00LKR'.strtoupper(md5('secret-xyz')))), $checkout['hash']);

        $payload = [
            'merchant_id' => '1211149', 'order_id' => $checkout['order_id'], 'payment_id' => '320025071278',
            'payhere_amount' => '2500.00', 'payhere_currency' => 'LKR', 'status_code' => '2',
        ];
        $payload['md5sig'] = strtoupper(md5('1211149'.$payload['order_id'].'2500.00LKR2'.strtoupper(md5('secret-xyz'))));

        $this->app['auth']->forgetGuards();
        $this->post('/api/payments/payhere/notify', $payload)->assertOk();
        $this->assertSame('paid', FeeInvoice::withoutGlobalScopes()->find($invoiceId)->status);

        // Replays are ignored
        $this->post('/api/payments/payhere/notify', $payload)->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payhere_notification_with_bad_signature_is_rejected(): void
    {
        config(['tuitionpro.payhere.merchant_id' => '1211149', 'tuitionpro.payhere.merchant_secret' => 'secret-xyz']);
        $this->post('/api/payments/payhere/notify', [
            'merchant_id' => '1211149', 'order_id' => 'INV1-1', 'payhere_amount' => '1.00', 'payhere_currency' => 'LKR',
            'status_code' => '2', 'md5sig' => 'FAKE',
        ])->assertStatus(400);
        $this->assertDatabaseCount('payments', 0);
    }
}
