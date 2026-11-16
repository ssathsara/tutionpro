<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function user(): User
    {
        /** @var User $u */
        $u = request()->user();

        return $u;
    }

    protected function ensureBatch(?int $batchId): void
    {
        abort_unless($this->user()->canAccessBatch($batchId), 403, 'You do not have access to this batch.');
    }

    protected function ensureStudent(int $studentId): void
    {
        abort_unless($this->user()->canAccessStudent($studentId), 403, 'You do not have access to this student.');
    }

    /** Easy-to-type initial password (letters + digits, no symbols) that the user should change. */
    protected function generatePassword(): string
    {
        return \Illuminate\Support\Str::password(10, letters: true, numbers: true, symbols: false);
    }

    protected function perPage(Request $request, int $default = 20): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }
}
