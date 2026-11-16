<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institute extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'settings' => 'array'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
