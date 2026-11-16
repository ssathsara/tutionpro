<?php

namespace App\Services;

use App\Jobs\SendSmsMessages;
use App\Models\AppNotification;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Central place for user-facing notifications: in-app notifications (bulk inserted)
 * plus optional SMS (queued; the primary channel parents use in Sri Lanka).
 */
class Notifier
{

    /**
     * @param  iterable<User>|User  $users
     */
    public function notify(iterable|User $users, string $type, string $title, ?string $body = null, ?string $link = null, bool $sms = false): int
    {
        $users = $users instanceof User ? collect([$users]) : Collection::make($users);
        $users = $users->filter()->unique('id');
        $now = now();
        $instituteId = app(Tenant::class)->id();

        $rows = $users->map(fn (User $u) => [
            'institute_id' => $u->institute_id ?? $instituteId,
            'user_id' => $u->id,
            'type' => $type,
            'title' => mb_substr($title, 0, 250),
            'body' => $body,
            'link' => $link,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            AppNotification::insert($chunk);
        }

        if ($sms) {
            // Sent by a queue worker in chunks with retries, so a large audience never blocks the request
            // (a gateway takes ~0.2s per message: 10,000 messages would be over half an hour).
            $text = trim($title.($body ? ': '.$body : ''));
            $messages = $users->filter(fn (User $u) => $u->phone)->map(fn (User $u) => [$u->phone, $text])->values()->all();
            SendSmsMessages::sendInChunks($instituteId, $messages);
        }

        return count($rows);
    }
}
