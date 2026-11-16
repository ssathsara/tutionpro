<?php

namespace App\Services\Sms;

use App\Models\SmsLog;
use App\Support\Phone;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends SMS through the configured driver and records every message in sms_logs.
 */
class SmsGateway
{
    public function send(?string $to, string $message): bool
    {
        $number = Phone::normalize($to);
        if (! $number) {
            return false;
        }

        $driver = config('tuitionpro.sms.driver', 'log');
        $status = 'logged';
        $response = null;

        if ($driver === 'notifylk') {
            $cfg = config('tuitionpro.sms.notifylk');
            try {
                $res = Http::timeout(10)->get($cfg['endpoint'], [
                    'user_id' => $cfg['user_id'],
                    'api_key' => $cfg['api_key'],
                    'sender_id' => $cfg['sender_id'],
                    'to' => $number,
                    'message' => $message,
                ]);
                $status = $res->successful() ? 'sent' : 'failed';
                $response = $res->body();
            } catch (Throwable $e) {
                $status = 'failed';
                $response = $e->getMessage();
            }
        }

        SmsLog::create([
            'institute_id' => app(Tenant::class)->id(),
            'to' => $number,
            'message' => mb_substr($message, 0, 640),
            'driver' => $driver,
            'status' => $status,
            'response' => $response ? mb_substr($response, 0, 1000) : null,
        ]);

        return $status !== 'failed';
    }
}
