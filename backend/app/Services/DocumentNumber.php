<?php

namespace App\Services;

use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Allocates invoice/receipt numbers such as INV-202611-00042 per institute per month.
 *
 * Numbers come from a counter row locked with SELECT ... FOR UPDATE, so two admins saving at
 * the same moment (or PayHere notifications arriving together) can never receive the same
 * number, and the counter keeps working past 99,999 (it just gets more digits).
 */
class DocumentNumber
{
    /** @return string[] $count consecutive numbers */
    public function next(string $prefix, string $model, string $column, int $count = 1, ?int $instituteId = null): array
    {
        if ($count < 1) {
            return [];
        }
        $iid = $instituteId ?? app(Tenant::class)->id();
        $key = $prefix.'-'.now()->format('Ym');

        return DB::transaction(function () use ($iid, $key, $model, $column, $count) {
            $where = ['institute_id' => $iid, 'key' => $key];
            if (! DB::table('number_sequences')->where($where)->exists()) {
                // First number this month: continue from any numbers that already exist (e.g. imported data).
                DB::table('number_sequences')->insertOrIgnore($where + [
                    'last_value' => $this->existingMax($iid, $key, $model, $column),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $row = DB::table('number_sequences')->where($where)->lockForUpdate()->first();
            $start = (int) $row->last_value + 1;
            $end = (int) $row->last_value + $count;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_value' => $end, 'updated_at' => now()]);

            return array_map(fn ($n) => sprintf('%s-%05d', $key, $n), range($start, $end));
        });
    }

    public function one(string $prefix, string $model, string $column): string
    {
        return $this->next($prefix, $model, $column)[0];
    }

    private function existingMax(int $iid, string $key, string $model, string $column): int
    {
        // Longest string first, so "100000" ranks above "99999".
        $last = $model::withoutGlobalScopes()->where('institute_id', $iid)->where($column, 'like', $key.'-%')
            ->orderByRaw("length($column) desc")->orderByDesc($column)->value($column);

        return $last ? (int) substr($last, strrpos($last, '-') + 1) : 0;
    }
}
