<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserQuota extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'prompt_count',
        'token_count',
        'doc_upload_count',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check-first lookup to avoid insert-first unique violations
     * under SQLite/concurrent double-submit on (user_id, date).
     */
    public static function forToday(int $userId): self
    {
        $date = now()->toDateString();

        // whereDate: the `date` cast serializes to datetime on write,
        // so plain where('date', 'Y-m-d') misses on SQLite.
        $quota = static::where('user_id', $userId)->whereDate('date', $date)->first();

        if ($quota) {
            return $quota;
        }

        try {
            return static::create([
                'user_id' => $userId,
                'date' => $date,
                'prompt_count' => 0,
                'token_count' => 0,
                'doc_upload_count' => 0,
            ]);
        } catch (\Throwable) {
            return static::where('user_id', $userId)->whereDate('date', $date)->firstOrFail();
        }
    }
}
