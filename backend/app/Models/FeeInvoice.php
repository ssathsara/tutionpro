<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeInvoice extends Model
{
    use BelongsToInstitute;

    protected $guarded = ['id', 'institute_id'];

    protected $casts = [
        'amount' => 'float',
        'discount' => 'float',
        'paid_amount' => 'float',
        'due_date' => 'date:Y-m-d',
    ];

    protected $appends = ['balance'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getBalanceAttribute(): float
    {
        return round(max(0, $this->amount - $this->discount - $this->paid_amount), 2);
    }

    /** Recalculate paid amount and status from completed payments. */
    public function refreshStatus(): void
    {
        $this->paid_amount = (float) $this->payments()->where('status', 'completed')->sum('amount');
        if ($this->status !== 'void') {
            $net = $this->amount - $this->discount;
            $this->status = $this->paid_amount <= 0 ? 'unpaid' : ($this->paid_amount + 0.001 >= $net ? 'paid' : 'partial');
        }
        $this->save();
    }
}
