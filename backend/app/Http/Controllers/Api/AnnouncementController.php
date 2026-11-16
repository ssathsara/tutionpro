<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementDispatcher $dispatcher) {}

    public function index(Request $request)
    {
        $user = $this->user();
        $ids = $user->visibleBatchIds();
        $audiences = match ($user->role) {
            User::STUDENT => ['all', 'students'],
            User::PARENT => ['all', 'parents'],
            User::TEACHER => ['all', 'teachers', 'students', 'parents'],
            default => Announcement::AUDIENCES,
        };

        return Announcement::query()->with(['batch:id,name', 'author:id,name'])
            ->whereIn('audience', $audiences)
            // Scheduled (future) announcements are visible to staff only.
            ->when(! $user->isStaff(), fn ($q) => $q->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now())))
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('batch_id')->orWhereIn('batch_id', $ids)))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->through(fn (Announcement $a) => array_merge($a->toArray(), [
                'batch_name' => $a->batch?->name ?? 'Whole institute',
                'author_name' => $a->author?->name,
            ]));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $announcement = Announcement::create(array_merge($data, [
            'created_by' => $this->user()->id,
            'published_at' => $data['published_at'] ?? now(),
        ]));

        // Scheduled announcements are sent by the announcements:send-scheduled command at published_at.
        $recipients = $this->dispatcher->dispatch($announcement);

        return response()->json(array_merge($announcement->fresh()->toArray(), [
            'recipients' => $recipients,
            'scheduled' => ! $this->dispatcher->isDue($announcement),
        ]), 201);
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->ensureCanManage($announcement);
        $data = $this->validateData($request);
        if (array_key_exists('published_at', $data) && ! $data['published_at']) {
            $data['published_at'] = $announcement->published_at ?? now();
        }
        $announcement->update($data);
        $this->dispatcher->dispatch($announcement); // e.g. a scheduled post moved to "now"

        return $announcement->fresh();
    }

    public function destroy(Announcement $announcement)
    {
        $this->ensureCanManage($announcement);
        $announcement->delete();

        return response()->noContent();
    }

    private function ensureCanManage(Announcement $a): void
    {
        $user = $this->user();
        abort_unless($user->isAdmin() || $a->created_by === $user->id, 403);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'is_pinned' => ['sometimes', 'boolean'],
            'send_sms' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ]);
        // Teachers may only post to their own batches.
        if (! $this->user()->isAdmin()) {
            abort_if(empty($data['batch_id']), 422, 'Select one of your batches.');
            $this->ensureBatch((int) $data['batch_id']);
            abort_if($data['audience'] === 'teachers', 422, 'Teachers cannot post staff-only announcements.');
        }

        return $data;
    }
}
