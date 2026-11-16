<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\ClassSessionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\FeeController;
use App\Http\Controllers\Api\InstituteController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ParentController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\ThreadController;
use App\Http\Controllers\Api\TimetableController;
use Illuminate\Support\Facades\Route;

/*
| Public endpoints
*/
Route::middleware('throttle:login')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register-institute', [AuthController::class, 'registerInstitute']);
});
Route::get('certificates/verify/{serial}', [CertificateController::class, 'verify'])->middleware('throttle:60,1');
Route::post('payments/payhere/notify', [FeeController::class, 'payhereNotify']);
Route::get('meta', fn () => [
    'grades' => config('tuitionpro.grades'),
    'mediums' => config('tuitionpro.mediums'),
    'payhere_enabled' => app(\App\Services\PayHere::class)->configured(),
]);

/*
| Authenticated endpoints. "tenant" binds the user's institute so every
| model query is automatically restricted to that institute.
*/
Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);
    Route::get('dashboard', DashboardController::class);

    // Platform administration
    Route::middleware('role:super_admin')->group(function () {
        Route::get('platform/stats', [InstituteController::class, 'stats']);
        Route::apiResource('institutes', InstituteController::class);
    });

    // Everything below is tenant-scoped (institute users only).
    Route::middleware('role:institute_admin,teacher,student,parent')->group(function () {
        $admin = 'role:institute_admin';
        $staff = 'role:institute_admin,teacher';
        $learner = 'role:student,parent';

        // People
        Route::get('students', [StudentController::class, 'index'])->middleware($staff);
        Route::get('students/{student}', [StudentController::class, 'show'])->middleware('role:institute_admin,teacher,parent');
        Route::get('students/{student}/attendance', [ClassSessionController::class, 'studentAttendance']);
        Route::middleware($admin)->group(function () {
            Route::post('students', [StudentController::class, 'store']);
            Route::put('students/{student}', [StudentController::class, 'update']);
            Route::delete('students/{student}', [StudentController::class, 'destroy']);
            Route::apiResource('teachers', TeacherController::class);
            Route::get('parents', [ParentController::class, 'index']);
            Route::post('parents', [ParentController::class, 'store']);
            Route::put('parents/{parent}', [ParentController::class, 'update']);
            Route::delete('parents/{parent}', [ParentController::class, 'destroy']);
            Route::get('audit-logs', [AuditLogController::class, 'index']);
        });
        Route::get('parent/children', [ParentController::class, 'children'])->middleware('role:parent');

        // Academic structure
        Route::get('subjects', [SubjectController::class, 'index']);
        Route::get('batches', [BatchController::class, 'index']);
        Route::get('batches/{batch}', [BatchController::class, 'show'])->middleware($staff);
        Route::get('timetable', [TimetableController::class, 'index']);
        Route::middleware($admin)->group(function () {
            Route::apiResource('subjects', SubjectController::class)->except(['index', 'show']);
            Route::post('batches', [BatchController::class, 'store']);
            Route::put('batches/{batch}', [BatchController::class, 'update']);
            Route::delete('batches/{batch}', [BatchController::class, 'destroy']);
            Route::post('batches/{batch}/students', [BatchController::class, 'enroll']);
            Route::put('batches/{batch}/students/{student}', [BatchController::class, 'updateEnrollment']);
            Route::delete('batches/{batch}/students/{student}', [BatchController::class, 'unenroll']);
            Route::post('timetable', [TimetableController::class, 'store']);
            Route::put('timetable/{timetableSlot}', [TimetableController::class, 'update']);
            Route::delete('timetable/{timetableSlot}', [TimetableController::class, 'destroy']);
        });

        // Classes, live classes & attendance
        Route::get('class-sessions', [ClassSessionController::class, 'index']);
        Route::get('class-sessions/{classSession}', [ClassSessionController::class, 'show']);
        Route::post('class-sessions/{classSession}/join', [ClassSessionController::class, 'join']);
        Route::middleware($staff)->group(function () {
            Route::post('class-sessions', [ClassSessionController::class, 'store']);
            Route::post('class-sessions/generate', [ClassSessionController::class, 'generate']);
            Route::put('class-sessions/{classSession}', [ClassSessionController::class, 'update']);
            Route::delete('class-sessions/{classSession}', [ClassSessionController::class, 'destroy']);
            Route::get('class-sessions/{classSession}/attendance', [ClassSessionController::class, 'attendance']);
            Route::post('class-sessions/{classSession}/attendance', [ClassSessionController::class, 'markAttendance']);
        });

        // Fees & payments
        Route::middleware('role:institute_admin,student,parent')->group(function () {
            Route::get('invoices', [FeeController::class, 'index']);
            Route::get('payments', [FeeController::class, 'payments']);
            Route::get('payments/{payment}/receipt', [FeeController::class, 'receipt']);
        });
        Route::post('invoices/{invoice}/payhere', [FeeController::class, 'payhereCheckout'])->middleware($learner);
        Route::middleware($admin)->group(function () {
            Route::get('invoices/summary', [FeeController::class, 'summary']);
            Route::post('invoices', [FeeController::class, 'store']);
            Route::post('invoices/generate', [FeeController::class, 'generate']);
            Route::put('invoices/{invoice}', [FeeController::class, 'update']);
            Route::delete('invoices/{invoice}', [FeeController::class, 'destroy']);
            Route::post('invoices/{invoice}/payments', [FeeController::class, 'recordPayment']);
        });

        // Online exams
        Route::get('exams', [ExamController::class, 'index']);
        Route::get('exams/{exam}', [ExamController::class, 'show']);
        Route::get('attempts/{attempt}', [ExamController::class, 'showAttempt']);
        Route::middleware('role:student')->group(function () {
            Route::post('exams/{exam}/start', [ExamController::class, 'start']);
            Route::put('attempts/{attempt}/answers', [ExamController::class, 'saveAnswers']);
            Route::post('attempts/{attempt}/submit', [ExamController::class, 'submit']);
        });
        Route::middleware($staff)->group(function () {
            Route::post('exams', [ExamController::class, 'store']);
            Route::put('exams/{exam}', [ExamController::class, 'update']);
            Route::delete('exams/{exam}', [ExamController::class, 'destroy']);
            Route::get('exams/{exam}/results', [ExamController::class, 'results']);
            Route::post('exams/{exam}/questions', [ExamController::class, 'storeQuestion']);
            Route::put('exams/{exam}/questions/{question}', [ExamController::class, 'updateQuestion']);
            Route::delete('exams/{exam}/questions/{question}', [ExamController::class, 'destroyQuestion']);
            Route::post('exams/{exam}/certificates', [CertificateController::class, 'issueForExam']);
        });

        // Lessons (videos, notes, tutorials)
        Route::get('lessons', [LessonController::class, 'index']);
        Route::get('lessons/progress', [LessonController::class, 'progress'])->middleware($staff);
        Route::get('lessons/{lesson}', [LessonController::class, 'show']);
        Route::get('lessons/{lesson}/file', [LessonController::class, 'file']);
        Route::post('lessons/{lesson}/complete', [LessonController::class, 'complete'])->middleware('role:student');
        Route::middleware($staff)->group(function () {
            Route::post('lessons', [LessonController::class, 'store']);
            Route::post('lessons/{lesson}', [LessonController::class, 'update']); // multipart-friendly update
            Route::put('lessons/{lesson}', [LessonController::class, 'update']);
            Route::delete('lessons/{lesson}', [LessonController::class, 'destroy']);
        });

        // Discussions & doubts
        Route::middleware('role:institute_admin,teacher,student')->group(function () {
            Route::get('threads', [ThreadController::class, 'index']);
            Route::post('threads', [ThreadController::class, 'store']);
            Route::get('threads/{thread}', [ThreadController::class, 'show']);
            Route::delete('threads/{thread}', [ThreadController::class, 'destroy']);
            Route::post('threads/{thread}/replies', [ThreadController::class, 'reply']);
            Route::post('threads/{thread}/resolve', [ThreadController::class, 'resolve']);
            Route::post('replies/{reply}/accept', [ThreadController::class, 'acceptAnswer']);
        });

        // Leaderboards & certificates
        Route::get('leaderboard', [LeaderboardController::class, 'index']);
        Route::get('certificates', [CertificateController::class, 'index']);
        Route::get('certificates/{certificate}', [CertificateController::class, 'show']);
        Route::middleware($staff)->group(function () {
            Route::post('certificates', [CertificateController::class, 'store']);
            Route::delete('certificates/{certificate}', [CertificateController::class, 'destroy']);
        });

        // Announcements & notifications
        Route::get('announcements', [AnnouncementController::class, 'index']);
        Route::middleware($staff)->group(function () {
            Route::post('announcements', [AnnouncementController::class, 'store']);
            Route::put('announcements/{announcement}', [AnnouncementController::class, 'update']);
            Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy']);
        });
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Analytics
        Route::middleware($staff)->prefix('analytics')->group(function () {
            Route::get('overview', [AnalyticsController::class, 'overview']);
            Route::get('revenue', [AnalyticsController::class, 'revenue']);
            Route::get('attendance', [AnalyticsController::class, 'attendance']);
            Route::get('exams', [AnalyticsController::class, 'exams']);
            Route::get('at-risk', [AnalyticsController::class, 'atRisk']);
        });
    });
});
