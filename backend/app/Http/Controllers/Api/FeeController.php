<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateMonthlyInvoices;
use App\Models\FeeInvoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\AuditLogger;
use App\Services\DocumentNumber;
use App\Services\InvoiceGenerator;
use App\Services\Notifier;
use App\Services\PayHere;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeeController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request)
    {
        $ids = $this->user()->visibleStudentIds();

        $q = FeeInvoice::query()->with(['student.user:id,name', 'batch:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids))
            ->when($request->status, fn ($q, $s) => $s === 'overdue'
                ? $q->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())
                : $q->where('status', $s))
            ->when($request->period, fn ($q, $p) => $q->where('period', $p))
            ->when($request->student_id, fn ($q, $s) => $q->where('student_id', $s))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%$s%")
                ->orWhereHas('student.user', fn ($u) => $u->where('name', 'like', "%$s%"))
                ->orWhereHas('student', fn ($u) => $u->where('admission_no', 'like', "%$s%"))))
            ->orderByDesc('id');

        return $q->paginate($this->perPage($request))->through(fn (FeeInvoice $i) => array_merge($i->toArray(), [
            'student_name' => $i->student?->user?->name,
            'admission_no' => $i->student?->admission_no,
            'batch_name' => $i->batch?->name,
            'is_overdue' => in_array($i->status, ['unpaid', 'partial']) && $i->due_date && $i->due_date->lt(today()),
        ]));
    }

    public function summary()
    {
        $base = FeeInvoice::query()->where('status', '!=', 'void');
        $monthStart = now()->startOfMonth();

        return [
            'collected_this_month' => (float) Payment::where('status', 'completed')->where('paid_at', '>=', $monthStart)->sum('amount'),
            'collected_today' => (float) Payment::where('status', 'completed')->whereDate('paid_at', today())->sum('amount'),
            'outstanding' => round((float) (clone $base)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('amount - discount - paid_amount')), 2),
            'overdue' => round((float) (clone $base)->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())
                ->sum(DB::raw('amount - discount - paid_amount')), 2),
            'overdue_count' => (clone $base)->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())->count(),
        ];
    }

    public function store(Request $request)
    {
        $iid = $this->user()->institute_id;
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('institute_id', $iid)],
            'batch_id' => ['nullable', Rule::exists('batches', 'id')->where('institute_id', $iid)],
            'period' => ['nullable', 'date_format:Y-m'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'due_date' => ['nullable', 'date'],
        ]);
        if (! empty($data['batch_id']) && ! empty($data['period'])
            && FeeInvoice::where(['student_id' => $data['student_id'], 'batch_id' => $data['batch_id'], 'period' => $data['period']])->exists()) {
            abort(422, 'An invoice for this student, batch and month already exists.');
        }

        $invoice = FeeInvoice::create(array_merge($data, [
            'invoice_no' => $this->nextNumber('INV', FeeInvoice::class, 'invoice_no'),
            'discount' => $data['discount'] ?? 0,
        ]));
        $this->notifyInvoice($invoice);
        AuditLogger::log('invoice.created', $invoice);

        return response()->json($invoice, 201);
    }

    /** Generate monthly invoices for every active enrollment. */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'due_day' => ['nullable', 'integer', 'between:1,28'],
            'batch_id' => ['nullable', 'integer'],
        ]);
        $batchId = isset($data['batch_id']) ? (int) $data['batch_id'] : null;
        $pending = app(InvoiceGenerator::class)->countPending($data['period'], $batchId);
        if ($pending === 0) {
            return response()->json(['message' => 'All active enrollments already have an invoice for this month.', 'created' => 0, 'queued' => false]);
        }

        // Runs in the background (queue worker); the admin gets a notification when it finishes.
        GenerateMonthlyInvoices::dispatch($this->user()->institute_id, $data['period'], (int) ($data['due_day'] ?? 10), $batchId, $this->user()->id);
        AuditLogger::log('invoice.generated', null, ['period' => $data['period'], 'count' => $pending]);

        return response()->json([
            'message' => "$pending invoices are being generated. You will get a notification when they are ready.",
            'created' => $pending,
            'queued' => true,
        ]);
    }

    public function update(Request $request, FeeInvoice $invoice)
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:1'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['void'])],
        ]);
        $invoice->update(array_filter($data, fn ($v) => $v !== null));
        $invoice->refreshStatus();
        AuditLogger::log('invoice.updated', $invoice, $data);

        return $invoice;
    }

    public function destroy(FeeInvoice $invoice)
    {
        abort_if($invoice->payments()->exists(), 422, 'Invoices with payments cannot be deleted. Void it instead.');
        $invoice->delete();

        return response()->noContent();
    }

    public function recordPayment(Request $request, FeeInvoice $invoice)
    {
        abort_if($invoice->status === 'void', 422, 'This invoice is void.');
        abort_if($invoice->balance <= 0, 422, 'This invoice is already fully paid.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$invoice->balance],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'card'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
        ], ['amount.max' => 'Amount cannot exceed the outstanding balance of Rs. '.number_format($invoice->balance, 2)]);

        $payment = DB::transaction(function () use ($data, $invoice) {
            $payment = Payment::create([
                'fee_invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'receipt_no' => $this->nextNumber('RCT', Payment::class, 'receipt_no'),
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_at' => $data['paid_at'] ?? now(),
                'received_by' => $this->user()->id,
                'status' => 'completed',
            ]);
            $invoice->refreshStatus();

            return $payment;
        });

        $this->notifyPayment($payment);
        AuditLogger::log('payment.recorded', $payment, ['amount' => $payment->amount, 'invoice' => $invoice->invoice_no]);

        return response()->json($payment->load('invoice'), 201);
    }

    public function payments(Request $request)
    {
        $ids = $this->user()->visibleStudentIds();

        return Payment::query()->with(['student.user:id,name', 'invoice:id,invoice_no,description', 'receiver:id,name'])
            ->when($ids !== null, fn ($q) => $q->whereIn('student_id', $ids))
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when($request->from, fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('paid_at', '<=', $d))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->through(fn (Payment $p) => array_merge($p->toArray(), [
                'student_name' => $p->student?->user?->name,
                'invoice_no' => $p->invoice?->invoice_no,
                'received_by_name' => $p->receiver?->name,
            ]));
    }

    public function receipt(Payment $payment)
    {
        $this->ensureStudent($payment->student_id);
        $payment->load(['invoice.batch:id,name', 'student.user:id,name,phone', 'receiver:id,name', 'institute']);

        return [
            'payment' => $payment,
            'institute' => $payment->institute?->only(['name', 'address', 'city', 'phone', 'email']),
            'student' => ['name' => $payment->student?->user?->name, 'admission_no' => $payment->student?->admission_no],
            'invoice' => $payment->invoice,
        ];
    }

    /** Begin an online PayHere payment for an invoice (student or parent). */
    public function payhereCheckout(FeeInvoice $invoice, PayHere $payhere)
    {
        $this->ensureStudent($invoice->student_id);
        abort_unless(in_array($invoice->status, ['unpaid', 'partial']), 422, 'This invoice has nothing to pay.');
        abort_unless($payhere->configured(), 503, 'Online payments are not configured for this institute yet.');

        $user = $this->user();
        $orderId = 'INV'.$invoice->id.'-'.now()->timestamp;
        $amount = $invoice->balance;
        $names = explode(' ', $user->name, 2);
        $frontend = rtrim(config('tuitionpro.frontend_url'), '/');

        return [
            'action' => $payhere->checkoutUrl(),
            'fields' => [
                'merchant_id' => $payhere->merchantId(),
                'return_url' => $frontend.'/fees?paid=1',
                'cancel_url' => $frontend.'/fees?cancelled=1',
                'notify_url' => url('/api/payments/payhere/notify'),
                'order_id' => $orderId,
                'items' => $invoice->description ?: $invoice->invoice_no,
                'currency' => 'LKR',
                'amount' => number_format($amount, 2, '.', ''),
                'first_name' => $names[0],
                'last_name' => $names[1] ?? '',
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'address' => '',
                'city' => $user->institute?->city ?? 'Colombo',
                'country' => 'Sri Lanka',
                'hash' => $payhere->checkoutHash($orderId, $amount),
            ],
        ];
    }

    /** Server-to-server notification from PayHere (public, signature verified). */
    public function payhereNotify(Request $request, PayHere $payhere)
    {
        $p = $request->all();
        if (! $payhere->verifyNotification($p)) {
            return response('Invalid signature', 400);
        }
        if ((int) ($p['status_code'] ?? 0) !== 2) {
            return response('Ignored', 200);
        }
        if (! preg_match('/^INV(\d+)-/', (string) ($p['order_id'] ?? ''), $m)) {
            return response('Unknown order', 400);
        }

        $invoice = FeeInvoice::withoutGlobalScopes()->find($m[1]);
        if (! $invoice) {
            return response('Unknown invoice', 404);
        }
        app(Tenant::class)->set($invoice->institute_id);

        if (Payment::where('reference', $p['payment_id'] ?? '')->where('method', 'payhere')->exists()) {
            return response('Duplicate', 200);
        }

        $payment = DB::transaction(function () use ($invoice, $p) {
            $payment = Payment::create([
                'fee_invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'receipt_no' => $this->nextNumber('RCT', Payment::class, 'receipt_no'),
                'amount' => (float) $p['payhere_amount'],
                'method' => 'payhere',
                'reference' => $p['payment_id'] ?? $p['order_id'],
                'paid_at' => now(),
                'status' => 'completed',
            ]);
            $invoice->refreshStatus();

            return $payment;
        });
        $this->notifyPayment($payment);

        return response('OK', 200);
    }

    private function notifyInvoice(FeeInvoice $invoice): void
    {
        $student = $invoice->student ?? Student::find($invoice->student_id);
        if (! $student) {
            return;
        }
        $student->loadMissing('user', 'guardians');
        $net = $invoice->amount - $invoice->discount;
        if ($net <= 0) {
            return;
        }
        $this->notifier->notify($student->notifiableUsers(), 'fee', 'New fee invoice '.$invoice->invoice_no,
            sprintf('Rs. %s due %s for %s', number_format($net, 2), $invoice->due_date?->format('d M Y') ?? 'now', $invoice->description ?? 'tuition'),
            '/fees');
    }

    private function notifyPayment(Payment $payment): void
    {
        $student = Student::with('user', 'guardians')->find($payment->student_id);
        if (! $student) {
            return;
        }
        $this->notifier->notify($student->notifiableUsers(), 'payment', 'Payment received',
            sprintf('Rs. %s received for %s. Receipt %s', number_format($payment->amount, 2), $student->user?->name, $payment->receipt_no),
            '/receipts/'.$payment->id, true);
    }

    private function nextNumber(string $prefix, string $model, string $column): string
    {
        return app(DocumentNumber::class)->one($prefix, $model, $column);
    }
}
