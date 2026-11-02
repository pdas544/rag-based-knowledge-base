<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvalRun extends Model
{
    protected $fillable = [
        'run_id',
        'mode',
        'status',
        'dataset_path',
        'dataset_hash',
        'git_sha',
        'chat_model',
        'embedding_model',
        'embedding_dim',
        'collection',
        'prompt_version',
        'judge_model',
        'top_k',
        'threshold',
        'question_count',
        'metrics',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'metadata' => 'array',
            'threshold' => 'float',
            'top_k' => 'integer',
            'question_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(EvalResult::class);
    }
}
