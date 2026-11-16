<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_no', 40);
            $table->string('period', 7)->nullable(); // YYYY-MM
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('status', 10)->default('unpaid'); // unpaid | partial | paid | void
            $table->timestamps();
            $table->unique(['institute_id', 'invoice_no']);
            $table->unique(['student_id', 'batch_id', 'period']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_no', 40);
            $table->decimal('amount', 10, 2);
            $table->string('method', 20); // cash | bank_transfer | card | payhere
            $table->string('reference')->nullable();
            $table->string('status', 10)->default('completed'); // pending | completed | failed
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['institute_id', 'receipt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('fee_invoices');
    }
};
