<?php

namespace App\Services\RAG;

class EvalDataset
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function load(string $path): array
    {
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($absolute)) {
            throw new \InvalidArgumentException("Dataset not found: {$path}");
        }

        $cases = [];
        $ids = [];
        foreach (file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $lineNo => $line) {
            $case = json_decode($line, true);
            if (! is_array($case)) {
                throw new \InvalidArgumentException('Invalid JSON on line '.($lineNo + 1).': '.json_last_error_msg());
            }

            foreach (['id', 'question', 'answerable', 'expected_answer', 'relevant_document_ids', 'relevant_chunk_ids', 'tags'] as $field) {
                if (! array_key_exists($field, $case)) {
                    throw new \InvalidArgumentException("Missing [{$field}] on line ".($lineNo + 1));
                }
            }

            if (isset($ids[$case['id']])) {
                throw new \InvalidArgumentException("Duplicate id [{$case['id']}] on line ".($lineNo + 1));
            }
            $ids[$case['id']] = true;

            $case['answerable'] = (bool) $case['answerable'];
            $case['relevant_document_ids'] = array_map('intval', (array) $case['relevant_document_ids']);
            $case['relevant_chunk_ids'] = array_map('intval', (array) $case['relevant_chunk_ids']);
            $case['tags'] = array_values(array_map('strval', (array) $case['tags']));
            $cases[] = $case;
        }

        if ($cases === []) {
            throw new \InvalidArgumentException("Dataset is empty: {$path}");
        }

        return $cases;
    }

    public static function hash(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);

        return hash_file('sha256', $absolute);
    }
}
