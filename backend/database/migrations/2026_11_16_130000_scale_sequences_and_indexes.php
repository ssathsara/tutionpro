<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free, collision-free document numbers (INV-202611-00001, RCT-...), allocated under a row lock.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('institute_id');
            $table->string('key', 40);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
            $table->unique(['institute_id', 'key']);
        });

        // Composite indexes for the report, dashboard and filter queries (tenant column first).
        Schema::table('fee_invoices', fn (Blueprint $t) => $t->index(['institute_id', 'status', 'due_date'], 'inv_inst_status_due'));
        Schema::table('fee_invoices', fn (Blueprint $t) => $t->index(['institute_id', 'period'], 'inv_inst_period'));
        Schema::table('payments', fn (Blueprint $t) => $t->index(['institute_id', 'status', 'paid_at'], 'pay_inst_status_paid'));
        Schema::table('class_sessions', fn (Blueprint $t) => $t->index(['batch_id', 'date'], 'cs_batch_date'));
        Schema::table('attendances', fn (Blueprint $t) => $t->index(['student_id', 'status'], 'att_student_status'));
        Schema::table('exam_attempts', fn (Blueprint $t) => $t->index(['student_id', 'status', 'submitted_at'], 'ea_student_status_sub'));
        Schema::table('exam_attempts', fn (Blueprint $t) => $t->index(['exam_id', 'status'], 'ea_exam_status'));
        Schema::table('batch_student', fn (Blueprint $t) => $t->index(['student_id', 'status'], 'bs_student_status'));
        Schema::table('students', fn (Blueprint $t) => $t->index(['institute_id', 'status'], 'stu_inst_status'));
        Schema::table('announcements', fn (Blueprint $t) => $t->index(['institute_id', 'published_at'], 'ann_inst_published'));
        Schema::table('threads', fn (Blueprint $t) => $t->index(['batch_id', 'status'], 'thr_batch_status'));
    }

    public function down(): void
    {
        $drop = [
            'fee_invoices' => ['inv_inst_status_due', 'inv_inst_period'], 'payments' => ['pay_inst_status_paid'],
            'class_sessions' => ['cs_batch_date'], 'attendances' => ['att_student_status'],
            'exam_attempts' => ['ea_student_status_sub', 'ea_exam_status'], 'batch_student' => ['bs_student_status'],
            'students' => ['stu_inst_status'], 'announcements' => ['ann_inst_published'], 'threads' => ['thr_batch_status'],
        ];
        foreach ($drop as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $i) {
                    $t->dropIndex($i);
                }
            });
        }
        Schema::dropIfExists('number_sequences');
    }
};
