<?php

use App\Models\Announcement;
use App\Services\AnnouncementDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Sends announcements whose scheduled time has arrived. Runs every minute via the
| scheduler (`php artisan schedule:work` in development, cron or the docker
| "scheduler" service in production).
*/
Artisan::command('announcements:send-scheduled', function (AnnouncementDispatcher $dispatcher) {
    $due = Announcement::withoutGlobalScopes()
        ->whereNull('notified_at')
        ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
        ->orderBy('published_at')
        ->limit(200)
        ->get();

    foreach ($due as $announcement) {
        $count = $dispatcher->dispatch($announcement);
        $this->info("Sent \"{$announcement->title}\" to {$count} recipient(s)");
    }
})->purpose('Send scheduled announcements that are now due');

Schedule::command('announcements:send-scheduled')->everyMinute()->withoutOverlapping();

// Every login creates an API token; remove expired ones so the token table (checked on every
// request) does not grow forever.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
