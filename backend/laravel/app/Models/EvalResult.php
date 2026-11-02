<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvalResult extends Model
{
    protected $fillable = [
        'eval_run_id',
        'question_id',
        'question',
        'answerable',
        'answer',
        'expected_answer',
        'retrieved',
        'relevant_chunk_ids',
        'scores',
        'judge_raw',
        'latency_ms',
        'tokens',
        'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'answerable' => 'boolean',
            'retrieved' => 'array',
            'relevant_chunk_ids' => 'array',
            'scores' => 'array',
            'judge_raw' => 'array',
            'latency_ms' => 'integer',
            'tokens' => 'integer',
            'cost_usd' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EvalRun::class, 'eval_run_id');
    }
}
