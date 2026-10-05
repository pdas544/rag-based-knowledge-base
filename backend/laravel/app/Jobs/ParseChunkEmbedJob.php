<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\LLM\LLMFactory;
use App\Services\Qdrant\QdrantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class ParseChunkEmbedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $documentId) {}

    public function handle(QdrantService $qdrant): void
    {
        $document = Document::find($this->documentId);
        if (! $document || in_array($document->status, ['ready', 'rejected'])) {
            return;
        }

        $document->update(['status' => 'processing', 'error' => null]);

        try {
            $text = $this->extractText($document);
            $text = (string) preg_replace('/\s+/u', ' ', trim($text));

            if ($text === '') {
                throw new \RuntimeException('No extractable text found.');
            }

            $chunks = $this->chunkText($text);
            $now = now()->toDateTimeString();

            $rows = [];
            foreach ($chunks as $i => $chunk) {
                $rows[] = [
                    'document_id' => $document->id,
                    'chunk_index' => $i,
                    'content' => $chunk,
                    'tokens' => (int) (strlen($chunk) / 4),
                    'qdrant_point_id' => null,
                    'created_at' => $now,
                ];
            }
            $document->chunks()->delete();
            DocumentChunk::insert($rows);

            // Without an API key there is nothing to embed with — keep
            // chunks locally and still advance to review (vectors skipped).
            if (config('services.llm.openrouter_api_key', '') === '') {
                Log::warning('ParseChunkEmbedJob: OPENROUTER_API_KEY empty, skipping embed.', [
                    'document_id' => $document->id,
                ]);
                $document->update(['status' => 'awaiting_review', 'chunk_count' => count($rows)]);

                return;
            }

            $vectors = LLMFactory::embeddings()->embedBatch(array_column($rows, 'content'));

            $qdrant->createCollection();
            $points = [];
            foreach ($rows as $i => $row) {
                $points[] = [
                    'id' => (string) Str::uuid(),
                    'vector' => $vectors[$i] ?? [],
                    'payload' => [
                        'document_id' => $document->id,
                        'owner_user_id' => $document->user_id,
                        'chunk_index' => $row['chunk_index'],
                        'text' => $row['content'],
                        'doc_title' => $document->filename,
                        'mime' => $document->mime,
                        'created_at' => $now,
                        'status' => 'awaiting_review',
                        'is_global' => true,
                    ],
                ];
            }
            $qdrant->upsert($points, $document->qdrant_collection);

            foreach ($document->chunks()->orderBy('chunk_index')->get() as $i => $chunk) {
                $chunk->update(['qdrant_point_id' => $points[$i]['id']]);
            }

            $document->update(['status' => 'awaiting_review', 'chunk_count' => count($rows)]);
        } catch (\Throwable $e) {
            Log::error('ParseChunkEmbedJob failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            $document->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }
    }

    private function extractText(Document $document): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($document->path)) {
            throw new \RuntimeException('Source file missing: '.$document->path);
        }
        $abs = $disk->path($document->path);
        $mime = $document->mime;

        // Plain text first (txt/md, and anything text/*)
        if (str_starts_with($mime, 'text/') || in_array(pathinfo($document->filename, PATHINFO_EXTENSION), ['txt', 'md'])) {
            return (string) $disk->get($document->path);
        }

        if ($mime === 'application/pdf' || str_ends_with(strtolower($document->filename), '.pdf')) {
            $text = $this->extractPdf($abs);
            if (strlen($text) < 100) {
                $alt = $this->runBinary('pdftotext', [$abs, '-']);
                if (strlen($alt) > strlen($text)) {
                    $text = $alt;
                }
            }

            return $text;
        }

        if (in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
            || str_ends_with(strtolower($document->filename), '.docx')) {
            return $this->extractDocx($abs);
        }

        if (str_starts_with($mime, 'image/')) {
            // Scanned content: OCR is the only source
            return $this->runBinary('tesseract', [$abs, 'stdout']);
        }

        return '';
    }

    private function extractPdf(string $abs): string
    {
        if (! class_exists(Parser::class)) {
            return '';
        }

        try {
            $pdf = (new Parser)->parseFile($abs);

            return (string) $pdf->getText();
        } catch (\Throwable) {
            return '';
        }
    }

    private function extractDocx(string $abs): string
    {
        if (! class_exists(IOFactory::class)) {
            return '';
        }

        try {
            $phpWord = IOFactory::load($abs, 'Word2007');
            $out = [];
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $el) {
                    $out[] = $this->elementText($el);
                }
            }

            return implode("\n", array_filter($out));
        } catch (\Throwable) {
            return '';
        }
    }

    private function elementText(mixed $el): string
    {
        if ($el instanceof Text) {
            return (string) $el->getText();
        }
        if ($el instanceof TextRun) {
            $parts = [];
            foreach ($el->getElements() as $child) {
                $parts[] = $child instanceof Text ? (string) $child->getText() : '';
            }

            return implode('', $parts);
        }
        if ($el instanceof TextBreak) {
            return "\n";
        }

        return '';
    }

    private function runBinary(string $bin, array $args): string
    {
        try {
            $found = trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null'));
            if ($found === '') {
                return '';
            }
            $cmd = escapeshellcmd($found).' '.implode(' ', array_map(escapeshellarg(...), $args)).' 2>/dev/null';
            $out = shell_exec($cmd);

            return is_string($out) ? $out : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Recursive-style splitter: paragraphs -> sentences -> words,
     * ~512 tokens per chunk with ~80 token overlap (strlen/4 estimate).
     *
     * @return array<int, string>
     */
    public function chunkText(string $text, int $size = 512, int $overlap = 80): array
    {
        $maxChars = $size * 4;
        $overlapChars = min($overlap * 4, $maxChars - 1);

        // Phase 1: atomic units, each <= maxChars
        $paras = preg_split('/\n{2,}/u', $text) ?: [$text];
        $units = [];
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (strlen($p) <= $maxChars) {
                $units[] = $p;

                continue;
            }
            $sentences = preg_split('/(?<=[.!?])\s+/u', $p) ?: [$p];
            foreach ($sentences as $s) {
                $s = trim($s);
                if ($s === '') {
                    continue;
                }
                if (strlen($s) <= $maxChars) {
                    $units[] = $s;

                    continue;
                }
                $words = preg_split('/\s+/u', $s) ?: [$s];
                $piece = '';
                foreach ($words as $w) {
                    while (strlen($w) > $maxChars) {
                        if ($piece !== '') {
                            $units[] = $piece;
                            $piece = '';
                        }
                        $units[] = substr($w, 0, $maxChars);
                        $w = substr($w, $maxChars);
                    }
                    $candidate = $piece === '' ? $w : $piece.' '.$w;
                    if (strlen($candidate) > $maxChars) {
                        $units[] = $piece;
                        $piece = $w;
                    } else {
                        $piece = $candidate;
                    }
                }
                if ($piece !== '') {
                    $units[] = $piece;
                }
            }
        }

        // Phase 2: greedy accumulate with overlap carry
        $chunks = [];
        $current = '';
        foreach ($units as $u) {
            $candidate = $current === '' ? $u : $current."\n\n".$u;
            if (strlen($candidate) > $maxChars && $current !== '') {
                $chunks[] = $current;
                $carry = mb_substr($current, -$overlapChars, null, 'UTF-8');
                $current = strlen($carry."\n\n".$u) > $maxChars ? $u : $carry."\n\n".$u;
            } else {
                $current = $candidate;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map(trim(...), $chunks), fn ($c) => $c !== ''));
    }
}
