<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Thread;
use App\Models\ThreadReply;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Batch discussions and student doubts (Q&A). */
class ThreadController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request)
    {
        $ids = $this->user()->visibleBatchIds();

        return Thread::query()->with(['user:id,name,role', 'batch:id,name'])->withCount('replies')
            ->when($ids !== null, fn ($q) => $q->whereIn('batch_id', $ids))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->batch_id, fn ($q, $b) => $q->where('batch_id', $b))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $this->user()->id))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', "%$s%")->orWhere('body', 'like', "%$s%")))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->orderByDesc('updated_at')
            ->paginate($this->perPage($request))
            ->through(fn (Thread $t) => array_merge($t->toArray(), [
                'author_name' => $t->user?->name,
                'author_role' => $t->user?->role,
                'batch_name' => $t->batch?->name,
            ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('institute_id', $this->user()->institute_id)],
            'lesson_id' => ['nullable', 'integer', Rule::exists('lessons', 'id')->where('institute_id', $this->user()->institute_id)],
            'type' => ['required', Rule::in(['discussion', 'doubt'])],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $this->ensureBatch((int) $data['batch_id']);
        $thread = Thread::create(array_merge($data, ['user_id' => $this->user()->id]));

        if ($thread->type === 'doubt') {
            $teacher = Batch::with('teacher.user')->find($thread->batch_id)?->teacher?->user;
            if ($teacher) {
                $this->notifier->notify($teacher, 'doubt', 'New doubt: '.$thread->title, $this->user()->name, '/discussions/'.$thread->id);
            }
        }

        return response()->json($thread, 201);
    }

    public function show(Thread $thread)
    {
        $this->ensureBatch($thread->batch_id);
        $thread->load(['user:id,name,role', 'batch:id,name', 'lesson:id,title', 'replies.user:id,name,role']);

        return array_merge($thread->toArray(), [
            'author_name' => $thread->user?->name,
            'batch_name' => $thread->batch?->name,
            'can_moderate' => $this->canModerate($thread),
        ]);
    }

    public function reply(Request $request, Thread $thread)
    {
        $this->ensureBatch($thread->batch_id);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $reply = $thread->replies()->create(['user_id' => $this->user()->id, 'body' => $data['body']]);
        $thread->touch();

        if ($thread->user_id !== $this->user()->id) {
            $this->notifier->notify(User::find($thread->user_id), $thread->type, 'New reply: '.$thread->title,
                $this->user()->name.' replied', '/discussions/'.$thread->id);
        }

        return response()->json($reply->load('user:id,name,role'), 201);
    }

    public function resolve(Thread $thread)
    {
        $this->ensureBatch($thread->batch_id);
        abort_unless($this->canModerate($thread), 403);
        $thread->update(['status' => $thread->status === 'resolved' ? 'open' : 'resolved']);

        return $thread;
    }

    public function acceptAnswer(ThreadReply $reply)
    {
        $thread = Thread::findOrFail($reply->thread_id);
        $this->ensureBatch($thread->batch_id);
        abort_unless($this->canModerate($thread), 403);
        $thread->replies()->update(['is_answer' => false]);
        $reply->update(['is_answer' => true]);
        $thread->update(['status' => 'resolved']);

        return response()->json(['message' => 'Answer accepted']);
    }

    public function destroy(Thread $thread)
    {
        $this->ensureBatch($thread->batch_id);
        abort_unless($this->user()->isStaff() || $thread->user_id === $this->user()->id, 403);
        $thread->delete();

        return response()->noContent();
    }

    private function canModerate(Thread $thread): bool
    {
        return $this->user()->isStaff() || $thread->user_id === $this->user()->id;
    }
}
