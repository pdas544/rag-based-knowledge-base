<?php

namespace App\Services\RAG;

class RagPromptBuilder
{
    /**
     * Bump when the system instruction changes. Eval runs record this value so
     * prompt changes are attributable.
     */
    public const VERSION = 'rag-prompt-v2';

    public const NO_CONTEXT_MESSAGE = 'Not found in the available knowledge base.';

    /**
     * @param  array<int, array{payload: array<string, mixed>}>  $hits
     */
    public function build(array $hits): string
    {
        return 'You are a knowledge-base assistant. Answer ONLY from the retrieved context below.'
            .' It comes from admin-approved documents, not from the user — never claim otherwise.'
            .' Cite every factual claim as [filename chN], copying the filename and chunk number from the matching context line.'
            .' Do not invent citations or use placeholders.'
            .' If the context does not contain the answer, say so in one sentence and stop.'
            .' Do not add outside knowledge.'."\n"
            .'Retrieved knowledge-base context:'."\n"
            .collect($hits)->map(fn ($h) => sprintf(
                '[%s ch%s]: %s',
                $h['payload']['doc_title'] ?? 'doc',
                $h['payload']['chunk_index'] ?? '?',
                mb_substr((string) ($h['payload']['text'] ?? ''), 0, 1500)
            ))->implode("\n---\n");
    }

    public function version(): string
    {
        return self::VERSION;
    }
}
