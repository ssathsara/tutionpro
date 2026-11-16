<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\AttemptAnswer;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\ClassSession;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\FeeInvoice;
use App\Models\Institute;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Thread;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\ExamGrader;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo data. All demo accounts use the password "password".
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        mt_srand(2026);

        User::create([
            'name' => 'Platform Admin',
            'email' => 'super@tuitionpro.lk',
            'password' => self::PASSWORD,
            'role' => User::SUPER_ADMIN,
        ]);

        $this->seedInstitute('Pragathi Higher Education Institute', 'pragathi', 'Maharagama', 'Colombo', 24);
        $this->seedInstitute('Vidya Academy', 'vidya', 'Kandy', 'Kandy', 10);

        $this->call(SinhalaExamSeeder::class);

        app(Tenant::class)->set(null);
    }

    private function seedInstitute(string $name, string $slug, string $city, string $district, int $studentCount): void
    {
        $tenant = app(Tenant::class);
        $tenant->set(null);

        $institute = Institute::create([
            'name' => $name, 'slug' => $slug, 'city' => $city, 'district' => $district,
            'phone' => '0112'.mt_rand(100000, 999999), 'email' => "info@$slug.lk",
            'address' => mt_rand(10, 300).' High Level Road, '.$city, 'plan' => 'growth',
        ]);
        $tenant->set($institute->id);

        $admin = User::create(['name' => 'Nimal Perera', 'email' => "admin@$slug.lk", 'phone' => '0771234567', 'password' => self::PASSWORD, 'role' => User::ADMIN]);

        // Subjects & teachers
        $subjectDefs = [
            ['Combined Mathematics', 'CMATH', 'Kasun Jayawardena', 'BSc (Hons) Mathematics, University of Moratuwa'],
            ['Physics', 'PHY', 'Dilani Fernando', 'BSc Physics, University of Colombo'],
            ['Chemistry', 'CHEM', 'Mohamed Rizwan', 'BSc Chemistry, University of Peradeniya'],
            ['Science', 'SCI', 'Tharushi Wickramasinghe', 'BEd Science, Open University of Sri Lanka'],
            ['English', 'ENG', 'Priya Selvaraj', 'BA English, University of Jaffna'],
        ];
        $teachers = [];
        $subjects = [];
        foreach ($subjectDefs as $i => [$sub, $code, $tname, $qual]) {
            $subjects[$code] = Subject::create(['name' => $sub, 'code' => $code]);
            $email = $i === 0 ? "teacher@$slug.lk" : strtolower(explode(' ', $tname)[0]).".$slug@example.lk";
            $u = User::create(['name' => $tname, 'email' => $email, 'phone' => '07'.mt_rand(10000000, 89999999), 'password' => self::PASSWORD, 'role' => User::TEACHER]);
            $teachers[$code] = Teacher::create(['user_id' => $u->id, 'qualification' => $qual, 'specialization' => $sub, 'payment_rate' => 70, 'joined_on' => '2023-01-15']);
        }

        // Batches with weekly timetable
        $batchDefs = [
            ['2027 A/L Combined Maths - Theory', 'CMATH', 'A/L', 'Sinhala', 'physical', 3500, 'Hall A', [[6, '08:00', '12:00']]],
            ['2027 A/L Physics - Theory', 'PHY', 'A/L', 'Sinhala', 'hybrid', 3000, 'Hall B', [[0, '13:00', '17:00']]],
            ['2027 A/L Chemistry - Revision', 'CHEM', 'A/L', 'English', 'online', 2800, null, [[3, '18:30', '20:30']]],
            ['Grade 11 Science (O/L)', 'SCI', 'O/L', 'Sinhala', 'physical', 2000, 'Room 3', [[2, '15:00', '17:00'], [5, '15:00', '17:00']]],
            ['Grade 10 English', 'ENG', 'Grade 10', 'English', 'physical', 1800, 'Room 2', [[4, '16:00', '18:00']]],
        ];
        $batches = [];
        foreach ($batchDefs as [$bname, $code, $grade, $medium, $mode, $fee, $room, $slots]) {
            $b = Batch::create([
                'name' => $bname, 'subject_id' => $subjects[$code]->id, 'teacher_id' => $teachers[$code]->id,
                'grade' => $grade, 'medium' => $medium, 'mode' => $mode, 'monthly_fee' => $fee,
                'capacity' => 60, 'room' => $room, 'start_date' => now()->subMonths(4)->startOfMonth()->toDateString(),
            ]);
            foreach ($slots as [$day, $start, $end]) {
                TimetableSlot::create(['batch_id' => $b->id, 'day_of_week' => $day, 'start_time' => "$start:00", 'end_time' => "$end:00", 'room' => $room]);
            }
            $batches[] = $b;
        }

        // Students & parents
        $first = ['Sachini', 'Kavindu', 'Dinuka', 'Hiruni', 'Ravindu', 'Tharindu', 'Nethmi', 'Ishara', 'Pasindu', 'Anjali', 'Vihanga', 'Senuri',
            'Lahiru', 'Imesha', 'Chamod', 'Dulani', 'Ashen', 'Malsha', 'Kavya', 'Arun', 'Fathima', 'Ayaan', 'Nuwan', 'Sanduni', 'Thisara', 'Janani'];
        $last = ['Perera', 'Fernando', 'Silva', 'Jayasuriya', 'Bandara', 'Dissanayake', 'Rajapaksha', 'Herath', 'Gunawardena', 'Kumara',
            'Rathnayake', 'Wijesinghe', 'Sivakumar', 'Nazeer', 'Weerasinghe', 'Abeysekara'];
        $schools = ['Royal College', 'Visakha Vidyalaya', 'Ananda College', 'Devi Balika Vidyalaya', 'Nalanda College', 'Musaeus College',
            'D.S. Senanayake College', 'Museus College', 'Isipathana College', 'Sri Sumangala College'];

        $students = [];
        for ($i = 0; $i < $studentCount; $i++) {
            $fn = $first[$i % count($first)];
            $ln = $last[mt_rand(0, count($last) - 1)];
            $email = $i === 0 ? "student@$slug.lk" : strtolower($fn).'.'.strtolower($ln).($i + 1).".$slug@example.lk";
            $u = User::create(['name' => "$fn $ln", 'email' => $email, 'phone' => '07'.mt_rand(10000000, 89999999), 'password' => self::PASSWORD, 'role' => User::STUDENT]);
            $alOrOl = $i % 3 === 2 ? 'O/L' : 'A/L';
            $s = Student::create([
                'user_id' => $u->id,
                'admission_no' => sprintf('ST%s%04d', now()->format('Y'), $i + 1),
                'grade' => $alOrOl, 'school' => $schools[mt_rand(0, count($schools) - 1)],
                'gender' => in_array($fn, ['Sachini', 'Hiruni', 'Nethmi', 'Anjali', 'Senuri', 'Imesha', 'Dulani', 'Malsha', 'Kavya', 'Fathima', 'Sanduni', 'Janani']) ? 'female' : 'male',
                'date_of_birth' => Carbon::create($alOrOl === 'A/L' ? 2008 : 2010, mt_rand(1, 12), mt_rand(1, 28))->toDateString(),
                'guardian_name' => 'Mr. '.$ln, 'guardian_phone' => '07'.mt_rand(10000000, 89999999),
            ]);
            $students[] = $s;

            // Enrollment
            $pick = $alOrOl === 'A/L' ? [0, 1, 2] : [3, 4];
            foreach ($pick as $bi) {
                if (mt_rand(1, 100) <= 80 || $i === 0) {
                    $batches[$bi]->students()->attach($s->id, [
                        'enrolled_on' => now()->subMonths(3)->toDateString(), 'status' => 'active',
                        'discount_percent' => $i % 9 === 4 ? 50 : 0,
                    ]);
                }
            }

            // Parents for most students
            if ($i % 4 !== 3) {
                $pemail = $i === 0 ? "parent@$slug.lk" : 'parent.'.strtolower($ln).($i + 1).".$slug@example.lk";
                $p = User::create(['name' => ($i % 2 ? 'Mrs. ' : 'Mr. ').$ln, 'email' => $pemail, 'phone' => '07'.mt_rand(10000000, 89999999), 'password' => self::PASSWORD, 'role' => User::PARENT]);
                $p->children()->attach($s->id, ['relationship' => $i % 2 ? 'mother' : 'father']);
            }
        }

        // Past sessions (4 weeks) with attendance, plus the coming week.
        foreach ($batches as $b) {
            $enrolled = $b->students()->pluck('students.id');
            foreach ($b->slots as $slot) {
                for ($d = today()->subWeeks(4); $d->lte(today()->addDays(7)); $d->addDay()) {
                    if ($d->dayOfWeek !== $slot->day_of_week) {
                        continue;
                    }
                    $past = $d->lt(today());
                    $session = ClassSession::create([
                        'batch_id' => $b->id, 'date' => $d->toDateString(),
                        'start_time' => $slot->start_time, 'end_time' => $slot->end_time,
                        'type' => $b->mode === 'online' ? 'live' : 'physical',
                        'meeting_url' => $b->mode === 'online' ? 'https://meet.jit.si/tuitionpro-'.$slug.'-chem-'.$d->format('md') : null,
                        'status' => $past ? 'completed' : 'scheduled',
                        'title' => $past ? null : 'Weekly class',
                    ]);
                    if ($past) {
                        foreach ($enrolled as $sid) {
                            $r = mt_rand(1, 100);
                            Attendance::create([
                                'class_session_id' => $session->id, 'student_id' => $sid,
                                'status' => $r <= 80 ? 'present' : ($r <= 88 ? 'late' : ($r <= 97 ? 'absent' : 'excused')),
                                'method' => $b->mode === 'online' ? 'online' : 'manual', 'marked_by' => $b->teacher->user_id,
                            ]);
                        }
                    }
                }
            }
        }
        // A live revision class later today for the physics batch
        ClassSession::create([
            'batch_id' => $batches[1]->id, 'date' => today()->toDateString(), 'start_time' => '19:00:00', 'end_time' => '20:30:00',
            'type' => 'live', 'title' => 'Mechanics revision (live)', 'status' => 'scheduled',
            'meeting_url' => 'https://meet.jit.si/tuitionpro-'.$slug.'-physics-revision',
        ]);

        // Invoices for the last 3 months; older ones mostly paid.
        $seq = 1;
        $rseq = 1;
        foreach ([2, 1, 0] as $ago) {
            $period = now()->subMonths($ago);
            foreach ($batches as $b) {
                foreach ($b->students()->get() as $s) {
                    $discount = round($b->monthly_fee * $s->pivot->discount_percent / 100, 2);
                    $inv = FeeInvoice::create([
                        'student_id' => $s->id, 'batch_id' => $b->id,
                        'invoice_no' => sprintf('INV-%s-%05d', $period->format('Ym'), $seq++),
                        'period' => $period->format('Y-m'), 'description' => $b->name.' - '.$period->format('F Y'),
                        'amount' => $b->monthly_fee, 'discount' => $discount,
                        'due_date' => $period->copy()->startOfMonth()->day(10)->toDateString(),
                    ]);
                    $payChance = [2 => 95, 1 => 80, 0 => 45][$ago];
                    if (mt_rand(1, 100) <= $payChance) {
                        $full = mt_rand(1, 10) > 1;
                        Payment::create([
                            'fee_invoice_id' => $inv->id, 'student_id' => $s->id,
                            'receipt_no' => sprintf('RCT-%s-%05d', $period->format('Ym'), $rseq++),
                            'amount' => $full ? $b->monthly_fee - $discount : round(($b->monthly_fee - $discount) / 2, 2),
                            'method' => ['cash', 'cash', 'bank_transfer', 'card', 'payhere'][mt_rand(0, 4)],
                            'paid_at' => $period->copy()->startOfMonth()->addDays(mt_rand(1, min(20, max(1, $ago ? 25 : now()->day - 1)))),
                            'received_by' => $admin->id, 'status' => 'completed',
                        ]);
                    }
                    $inv->refreshStatus();
                }
            }
        }

        // Exams with auto-marked attempts
        $grader = new ExamGrader;
        $maths = $batches[0];
        $exam = Exam::create([
            'batch_id' => $maths->id, 'created_by' => $maths->teacher->user_id,
            'title' => 'Unit Test 1 - Differentiation', 'language' => 'en', 'instructions' => 'Answer all questions. Calculators are not allowed.',
            'duration_minutes' => 30, 'pass_percentage' => 50, 'max_attempts' => 1, 'is_published' => true, 'show_results' => true,
            'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(3),
        ]);
        $qs = [
            ['mcq', 'What is d/dx (x³)?', ['x²', '3x²', '3x', 'x³/3'], [1], 2],
            ['mcq', 'The derivative of sin x is', ['cos x', '-cos x', 'tan x', '-sin x'], [0], 2],
            ['true_false', 'The derivative of a constant is zero.', ['True', 'False'], [true], 1],
            ['numeric', 'If f(x) = 5x² then f\'(2) = ?', null, [20, 0], 2],
            ['short_answer', 'What is the derivative of eˣ? (write in terms of x)', null, ['e^x', 'eˣ', 'e to the x'], 1],
            ['multi_select', 'Which functions are differentiable everywhere on ℝ?', ['x²', '|x|', 'sin x', '1/x'], [0, 2], 2],
        ];
        $questions = [];
        foreach ($qs as $pos => [$type, $text, $opts, $correct, $marks]) {
            $questions[] = $exam->questions()->create(['type' => $type, 'question' => $text, 'options' => $opts, 'correct_answer' => $correct, 'marks' => $marks, 'position' => $pos]);
        }
        $wrongs = ['mcq' => [2], 'true_false' => [false], 'numeric' => [10], 'short_answer' => ['x'], 'multi_select' => [0, 1]];
        foreach ($maths->students()->get() as $s) {
            if (mt_rand(1, 100) > 85) {
                continue;
            }
            $skill = mt_rand(35, 98);
            $start = now()->subDays(mt_rand(4, 9))->setTime(mt_rand(8, 20), mt_rand(0, 59));
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->id, 'student_id' => $s->id, 'started_at' => $start, 'submitted_at' => $start->copy()->addMinutes(mt_rand(12, 29)),
                'status' => 'submitted', 'question_order' => collect($questions)->pluck('id')->all(),
            ]);
            $score = 0;
            $total = 0;
            foreach ($questions as $q) {
                $ans = mt_rand(1, 100) <= $skill ? $q->correct_answer : $wrongs[$q->type];
                if ($q->type === 'short_answer' && $ans === $q->correct_answer) {
                    $ans = [$q->correct_answer[0]];
                }
                $value = $q->type === 'multi_select' ? $ans : $ans[0];
                $ok = $grader->isCorrect($q, $value);
                AttemptAnswer::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $q->id, 'answer' => $ans, 'is_correct' => $ok, 'marks_awarded' => $ok ? $q->marks : 0]);
                $score += $ok ? $q->marks : 0;
                $total += $q->marks;
            }
            $attempt->update(['score' => $score, 'total_marks' => $total, 'percentage' => round($score / $total * 100, 2)]);
        }

        // An open exam students can take now
        $open = Exam::create([
            'batch_id' => $batches[1]->id, 'created_by' => $batches[1]->teacher->user_id,
            'title' => 'Weekly Quiz - Motion in a Straight Line', 'language' => 'en', 'instructions' => 'You have 15 minutes. Results are shown after submission.',
            'duration_minutes' => 15, 'pass_percentage' => 40, 'max_attempts' => 2, 'is_published' => true, 'shuffle_questions' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(6),
        ]);
        foreach ([
            ['mcq', 'The SI unit of acceleration is', ['m s⁻¹', 'm s⁻²', 'N', 'kg m s⁻¹'], [1], 1],
            ['numeric', 'A car accelerates from rest at 2 m s⁻² for 5 s. Its final velocity (m s⁻¹) is?', null, [10, 0], 2],
            ['true_false', 'Displacement is a scalar quantity.', ['True', 'False'], [false], 1],
            ['mcq', 'The area under a velocity-time graph gives', ['acceleration', 'displacement', 'force', 'momentum'], [1], 1],
        ] as $pos => [$type, $text, $opts, $correct, $marks]) {
            $open->questions()->create(['type' => $type, 'question' => $text, 'options' => $opts, 'correct_answer' => $correct, 'marks' => $marks, 'position' => $pos]);
        }

        // Lessons
        foreach ($batches as $bi => $b) {
            Lesson::create(['batch_id' => $b->id, 'created_by' => $b->teacher->user_id, 'type' => 'note', 'position' => 1,
                'title' => 'Course outline & syllabus', 'description' => 'What we will cover this term',
                'content' => "Welcome to {$b->name}.\n\nThis term we will cover the full syllabus with weekly paper discussions.\n\n• Bring a CR book and past papers\n• Weekly quizzes are held online\n• Use the Discussions page to post your doubts"]);
            Lesson::create(['batch_id' => $b->id, 'created_by' => $b->teacher->user_id, 'type' => 'tutorial', 'position' => 2,
                'title' => 'Tutorial 01', 'description' => 'Practice questions for week 1',
                'content' => "1. Attempt all questions before next class.\n2. Show all workings.\n3. Mark your doubts and post them in Discussions."]);
            Lesson::create(['batch_id' => $b->id, 'created_by' => $b->teacher->user_id, 'type' => 'video', 'position' => 3,
                'title' => 'Recorded class - Week 1', 'description' => 'Recording of the first class',
                'video_url' => 'https://www.youtube.com/watch?v=WUvTyaaNkzM']);
        }
        $firstStudent = $students[0];
        $lessonIds = Lesson::whereIn('batch_id', $firstStudent->batches()->pluck('batches.id'))->limit(4)->pluck('id');
        foreach ($lessonIds as $lid) {
            DB::table('lesson_progress')->insert(['lesson_id' => $lid, 'student_id' => $firstStudent->id, 'completed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Announcements
        Announcement::create(['title' => 'Poya day holiday', 'body' => 'There will be no classes on the coming Poya day. Make-up classes will be announced soon.', 'audience' => 'all', 'is_pinned' => true, 'created_by' => $admin->id, 'published_at' => now()->subDays(2)]);
        Announcement::create(['title' => 'Paper class this Saturday', 'body' => 'Bring your 2023 A/L past paper. We will discuss Part B in detail.', 'audience' => 'students', 'batch_id' => $maths->id, 'created_by' => $maths->teacher->user_id, 'published_at' => now()->subDay()]);
        Announcement::create(['title' => 'Fee payment reminder', 'body' => 'Monthly fees are due on the 10th. You can now pay online through PayHere.', 'audience' => 'parents', 'created_by' => $admin->id, 'published_at' => now()->subDays(5)]);

        // Discussions & doubts
        $t = Thread::create(['batch_id' => $maths->id, 'user_id' => $firstStudent->user_id, 'type' => 'doubt', 'title' => 'Chain rule with trigonometric functions',
            'body' => 'How do we differentiate sin(3x² + 1)? I get confused about which part to differentiate first.']);
        $t->replies()->create(['user_id' => $maths->teacher->user_id, 'body' => 'Differentiate the outer function first and keep the inside: cos(3x²+1), then multiply by the derivative of the inside (6x). Answer: 6x·cos(3x²+1).', 'is_answer' => true]);
        $t->update(['status' => 'resolved']);
        Thread::create(['batch_id' => $batches[1]->id, 'user_id' => $firstStudent->user_id, 'type' => 'doubt', 'title' => 'Sign of acceleration in free fall',
            'body' => 'When we take upward as positive, should g be -9.8 m s⁻² in every equation?']);
        Thread::create(['batch_id' => $maths->id, 'user_id' => $maths->teacher->user_id, 'type' => 'discussion', 'title' => 'Share your favourite past paper questions',
            'body' => 'Post one question from 2015-2023 papers that you found tricky. We will discuss the best ones in class.']);

        // Certificates for top performers
        foreach ($exam->attempts()->where('percentage', '>=', 80)->get() as $a) {
            Certificate::create(['student_id' => $a->student_id, 'batch_id' => $maths->id, 'exam_id' => $exam->id,
                'serial' => 'TP-'.now()->format('Y').'-'.strtoupper(substr(md5($slug.$a->id), 0, 8)),
                'title' => 'Certificate of Merit - Differentiation', 'type' => 'merit', 'issued_on' => now()->subDays(2), 'issued_by' => $maths->teacher->user_id]);
        }
    }
}
