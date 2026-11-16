<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        return Subject::withCount('batches')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        return response()->json(Subject::create($this->validateData($request)), 201);
    }

    public function update(Request $request, Subject $subject)
    {
        $subject->update($this->validateData($request, $subject));

        return $subject;
    }

    public function destroy(Subject $subject)
    {
        $subject->delete();

        return response()->noContent();
    }

    private function validateData(Request $request, ?Subject $subject = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120',
                Rule::unique('subjects')->where('institute_id', $this->user()->institute_id)->ignore($subject?->id)],
            'code' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
