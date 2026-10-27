<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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

    /**
     * Atomically reserve one prompt against today's cap.
     *
     * A single UPDATE ... WHERE prompt_count < limit avoids the check-then-
     * increment race: two concurrent requests can no longer both observe
     * 29 and push the counter past the cap.
     */
    public static function tryConsumePrompt(int $userId, int $promptLimit = 30, int $tokenLimit = 20000): bool
    {
        $quota = static::forToday($userId);

        $affected = static::query()
            ->whereKey($quota->getKey())
            ->where('prompt_count', '<', $promptLimit)
            ->where('token_count', '<', $tokenLimit)
            ->update(['prompt_count' => DB::raw('prompt_count + 1')]);

        return $affected > 0;
    }

    /**
     * Record tokens after the reply is generated. Atomic increment, so
     * concurrent streams cannot overwrite each other's usage.
     */
    public static function recordTokens(int $userId, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        static::forToday($userId)->increment('token_count', $tokens);
    }
}
