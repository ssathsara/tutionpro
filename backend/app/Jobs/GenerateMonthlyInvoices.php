<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\Notifier;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Generates a month's invoices in the background and tells the admin when done.
 * Safe to retry: the generator skips enrollments that already have an invoice.
 */
class GenerateMonthlyInvoices implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;
    public array $backoff = [30, 120];

    public function __construct(public int $instituteId, public string $period, public int $dueDay, public ?int $batchId, public ?int $requestedBy) {}

    public function handle(InvoiceGenerator $generator, Notifier $notifier): void
    {
        app(Tenant::class)->set($this->instituteId);
        $created = $generator->generate($this->period, $this->dueDay, $this->batchId);

        if ($this->requestedBy && ($admin = User::find($this->requestedBy))) {
            $notifier->notify($admin, 'fee', 'Invoices generated',
                sprintf('%d invoice(s) created for %s.', $created, Carbon::createFromFormat('Y-m-d', $this->period.'-01')->format('F Y')), '/fees');
        }
    }
}
