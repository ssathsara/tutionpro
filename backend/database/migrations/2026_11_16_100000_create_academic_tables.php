<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['institute_id', 'name']);
        });

        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nic', 12)->nullable();
            $table->string('qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->decimal('payment_rate', 5, 2)->default(0); // % share of batch fees
            $table->date('joined_on')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('admission_no', 30);
            $table->string('grade', 20)->nullable();     // Grade 6..11, O/L, A/L
            $table->string('school')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 20)->nullable();
            $table->string('status', 20)->default('active'); // active | inactive | graduated
            $table->timestamps();
            $table->unique(['institute_id', 'admission_no']);
        });

        Schema::create('guardian_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 30)->default('parent');
            $table->timestamps();
            $table->unique(['parent_id', 'student_id']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('grade', 20)->nullable();
            $table->string('medium', 20)->default('Sinhala'); // Sinhala | Tamil | English
            $table->string('mode', 20)->default('physical'); // physical | online | hybrid
            $table->decimal('monthly_fee', 10, 2)->default(0);
            $table->unsignedInteger('capacity')->nullable();
            $table->string('room')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('batch_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('enrolled_on')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0); // scholarships / sibling discounts
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['batch_id', 'student_id']);
        });

        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room')->nullable();
            $table->timestamps();
        });

        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('type', 20)->default('physical'); // physical | live
            $table->string('meeting_url')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled | completed | cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['institute_id', 'date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10); // present | absent | late | excused
            $table->string('method', 10)->default('manual'); // manual | qr | online
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks')->nullable();
            $table->timestamps();
            $table->unique(['class_session_id', 'student_id']);
        });
    }

    public function down(): void
    {
        foreach (['attendances', 'class_sessions', 'timetable_slots', 'batch_student', 'batches', 'guardian_student', 'students', 'teachers', 'subjects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
