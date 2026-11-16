<?php

namespace App\Jobs;

use App\Services\Sms\SmsGateway;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a chunk of SMS messages in the background. Messages that fail (gateway down,
 * timeout) are retried in a new job with increasing delay, up to MAX_ROUNDS times;
 * messages that were delivered are never sent twice.
 */
class SendSmsMessages implements ShouldQueue
{
    use Queueable;

    public const CHUNK = 100;
    public const MAX_ROUNDS = 3;

    public int $tries = 1;
    public int $timeout = 300;

    /** @param array<int, array{0: string, 1: string}> $messages [phone, text] pairs */
    public function __construct(public ?int $instituteId, public array $messages, public int $round = 1) {}

    public function handle(SmsGateway $sms): void
    {
        app(Tenant::class)->set($this->instituteId);
        $failed = [];
        foreach ($this->messages as [$to, $text]) {
            if (! $sms->send($to, $text)) {
                $failed[] = [$to, $text];
            }
        }
        if ($failed && $this->round < self::MAX_ROUNDS) {
            self::dispatch($this->instituteId, $failed, $this->round + 1)->delay(now()->addMinutes(2 * $this->round));
        }
    }

    /** Queue messages in chunks. (Not named "queue": Laravel's dispatcher reserves that method name on jobs.) */
    public static function sendInChunks(?int $instituteId, array $messages): void
    {
        foreach (array_chunk($messages, self::CHUNK) as $chunk) {
            self::dispatch($instituteId, $chunk);
        }
    }
}
