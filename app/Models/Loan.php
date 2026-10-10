<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Loan extends Model
{
    public $timestamps = false;
    protected $table = 'loan';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'loaned_at' => 'datetime',
    ];

    protected $appends = ['status'];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaned_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class, 'loan_id');
    }

    public function getStatusAttribute(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        if ($items->isEmpty()) {
            return 'closed';
        }

        $allReturned = true;
        $hasOverdue = false;
        $now = Carbon::now('UTC');

        foreach ($items as $item) {
            if (is_null($item->returned_at)) {
                $allReturned = false;
                $dueAt = Carbon::parse($item->due_at, 'UTC');
                if ($dueAt->lt($now)) {
                    $hasOverdue = true;
                }
            }
        }

        if ($allReturned) {
            return 'closed';
        }

        if ($hasOverdue) {
            return 'overdue';
        }

        return 'open';
    }
}
