<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Leaderboard;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request, Leaderboard $leaderboard)
    {
        $data = $request->validate([
            'batch_id' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:all,month'],
        ]);
        $user = $this->user();

        $batchId = $data['batch_id'] ?? null;
        if (! $batchId) {
            $batchId = Batch::visibleTo($user)->orderBy('name')->value('id');
        }
        if (! $batchId) {
            return ['batch' => null, 'rows' => []];
        }
        $this->ensureBatch((int) $batchId);
        $batch = Batch::findOrFail($batchId);

        $since = ($data['period'] ?? 'all') === 'month' ? now()->startOfMonth() : null;
        // Ranking a batch scans all its exams, attendance and lessons; cache it briefly.
        $rows = \Illuminate\Support\Facades\Cache::remember(
            "leaderboard:{$batch->institute_id}:{$batch->id}:".($data['period'] ?? 'all'),
            \App\Services\Analytics::TTL,
            fn () => $leaderboard->forBatch($batch, $since),
        );

        $mine = $user->visibleStudentIds();

        return [
            'batch' => $batch->only(['id', 'name']),
            'rows' => $rows->map(fn ($r) => array_merge($r, ['is_me' => $mine !== null && $user->isStaff() === false && in_array($r['student_id'], $mine, true)])),
        ];
    }
}
