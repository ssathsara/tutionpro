# TuitionPro

Multi-tenant tuition class management and learning platform for Sri Lankan institutes.

- **Backend:** Laravel 12 REST API, Sanctum token auth, MySQL/MariaDB (`backend/`)
- **Frontend:** Vue 3 + TypeScript + Vite + Pinia + Vue Router (`frontend/`)

## Features

| Area | What's included |
|---|---|
| Institutes & people | Self-service institute signup, students (auto admission numbers), teachers (NIC validation), parents linked to children, subjects, batches (grade, Sinhala/Tamil/English medium, physical/online/hybrid, capacity) |
| Timetable | Weekly slots with room and teacher clash detection; generate class sessions from the timetable |
| Attendance | Per-class roster marking (present/late/absent/excused), SMS and in-app absence alerts to parents, online attendance recorded when students join live classes |
| Fees | Monthly invoice generation with per-student discounts, partial payments, receipts (printable), overdue tracking, PayHere online payments with signature-verified notifications |
| Online exams | MCQ, multi-select, true/false, short answer, numeric (with tolerance); timed attempts with server-side deadline, autosave, shuffle, attempt limits, automatic marking, item analysis, CSV export |
| Learning | Video lessons (YouTube/Vimeo embeds), notes and tutorials with file uploads, completion tracking |
| Communication | Announcements by audience/batch (optional SMS), in-app notifications, discussions and doubt Q&A with accepted answers |
| Engagement | Batch leaderboards (exams 60%, attendance 30%, lessons 10%), certificates with public serial verification |
| Live classes | Zoom/Meet links or auto-generated Jitsi rooms, notifications, recordings |
| Analytics | Revenue, payment methods, weekly/batch attendance, exam performance, at-risk students |
| Security | Row-level tenant isolation (bound before route-model binding), role middleware plus per-batch/per-student access checks, login rate limiting, audit log, security headers, mass-assignment protection, password policy |

### Roles

`super_admin` (platform), `institute_admin`, `teacher` (own batches only), `student`, `parent` (own children only).

## Local development

Requirements: PHP 8.2+, Composer, Node 20+, MySQL 8 / MariaDB 10.4+.

```bash
# Backend
cd backend
cp .env.example .env          # set DB_* credentials
composer install
php artisan key:generate
php artisan migrate --seed    # demo data
php artisan storage:link
php artisan serve             # http://127.0.0.1:8000
php artisan queue:work        # REQUIRED (separate terminal): sends SMS and generates monthly invoices
php artisan schedule:work     # separate terminal: sends scheduled announcements
# or run everything at once: composer run dev

# Frontend (new terminal)
cd frontend
npm install
npm run dev                   # http://localhost:5173 (proxies /api to :8000)
```

### Demo accounts (password: `password`)

| Role | Email |
|---|---|
| Platform admin | super@tuitionpro.lk |
| Institute admin | admin@pragathi.lk |
| Teacher | teacher@pragathi.lk |
| Student | student@pragathi.lk |
| Parent | parent@pragathi.lk |

A second institute (`admin@vidya.lk`) is seeded so you can see tenant isolation.

## Tests

```bash
cd backend && php artisan test     # feature + unit tests (SQLite in-memory)
cd frontend && npm test            # Vitest
cd frontend && npm run typecheck   # vue-tsc
```

## Integrations

Configure in `backend/.env`:

- **SMS:** `SMS_DRIVER=log` records messages in `sms_logs`. `SMS_DRIVER=notifylk` sends through Notify.lk (`NOTIFYLK_USER_ID`, `NOTIFYLK_API_KEY`, `NOTIFYLK_SENDER_ID`).
- **PayHere:** set `PAYHERE_MERCHANT_ID`, `PAYHERE_MERCHANT_SECRET`, and `PAYHERE_SANDBOX`. The notify URL is `{APP_URL}/api/payments/payhere/notify` and must be publicly reachable. The "Pay online" button appears for students/parents once configured.
- **CORS:** `CORS_ALLOWED_ORIGINS` (defaults to `FRONTEND_URL`).

## Deployment

`docker compose up -d --build` runs MySQL, Redis (cache + job queue), the API (php-fpm with opcache), a queue worker (SMS, invoice generation), the scheduler (scheduled announcements) and nginx serving the SPA and proxying `/api`. Add queue workers for large SMS volumes with `docker compose up -d --scale queue=3`. Then run:

```bash
docker compose exec api php artisan migrate --force
```

### Performance at scale

Measured with one institute of 5,000 students and about 1.25 million rows (6 months of attendance, fees and exams):

- **Everyday screens:** lists, searches, dashboards and the student picker take about the same time as an empty request.
- **Reports:** under 1.2 s on first load, then served from a 5-minute cache.
- **Bulk invoices:** 15,000 monthly invoices are generated in the background in about 2 s.
- **Large SMS sends:** an SMS announcement to about 10,000 people returns in about 1.5 s; delivery continues in the background, with retries.

Production checklist: `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, HTTPS in front of nginx, strong DB passwords, and backups of the `db-data` and `storage` volumes. Remove or change the demo accounts if you seeded them.

## API overview

All endpoints are under `/api`, JSON, Bearer token (`POST /api/auth/login`). Main resources: `students`, `teachers`, `parents`, `subjects`, `batches` (+ `/students` enrollment), `timetable`, `class-sessions` (+ `/attendance`, `/join`, `/generate`), `invoices` (+ `/generate`, `/payments`, `/payhere`), `payments/{id}/receipt`, `exams` (+ `/questions`, `/start`, `/results`, `/certificates`), `attempts` (+ `/answers`, `/submit`), `lessons` (+ `/complete`, `/progress`), `threads`, `announcements`, `notifications`, `leaderboard`, `certificates` (+ public `/verify/{serial}`), `analytics/*`, `dashboard`, `audit-logs`, `institutes` (platform admin). Run `php artisan route:list --path=api` for the full list.
