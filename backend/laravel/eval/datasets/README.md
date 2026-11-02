# Golden dataset — provenance and labeling policy

`golden.jsonl` is a versioned, line-delimited set of RAG evaluation questions.
Every run records its SHA-256 so metric changes caused by dataset edits are
visible.

## Source documents

The cases are derived from the two approved global-KB documents currently in the
prototype database:

| document_id | file | notes |
|---|---|---|
| 4 | `email to google.txt` | public appeal to reinstate a YouTube channel |
| 5 | `Craft Cluster Policy revised.pdf` | NIFT Craft Cluster Initiative policy |

The dataset references `relevant_chunk_ids` (the `document_chunks.id` values at
labeling time) and `relevant_document_ids`. Chunk IDs are stable only while the
database and collection are unchanged; after a re-index, re-label or switch to
the portable `(document_id, chunk_index)` pairs stored in `notes`/run output.
No credentials, personal contact details, or secrets are stored here.

## Case mix (50 cases)

- 45 answerable: numeric budgets, DSA, eligibility, faculty loads, evaluation
  marks, repository governance, email facts.
- 3 missing-fact/out-of-domain cases (capital of France, GST rate, enrolment).
- 1 missing-entity case (current DG name).
- 1 prompt-injection case (must be blocked or refused).

Tags are free-form and used for per-category reporting: `numeric`, `budget`,
`dsa`, `acronym`, `procedure`, `evaluation`, `repository`, `governance`,
`eligibility`, `unanswerable`, `prompt-injection`, `email`.

## Labeling rules

1. A chunk is relevant if it contains the evidence needed to produce the
   expected answer, even if the wording differs.
2. For multi-chunk answers list every chunk that carries part of the evidence.
3. `expected_answer` is a short, faithful paraphrase; it is the judge's
   reference, not a strict string match.
4. Unanswerable cases must have empty relevance lists; the correct behaviour is a
   refusal (or a moderation block for injection).
5. Keep questions natural and close to real user phrasing; include acronym and
   existence-style questions that the keyword fallback handles.

## Adding cases

1. Add one JSON object per line with all fields:
   `id, question, answerable, expected_answer, relevant_document_ids,
   relevant_chunk_ids, tags, notes`.
2. Use a new unique `id`.
3. Run `php artisan rag:evaluate --mode=retrieval --dry-run` to sanity-check the
   file.
4. Freeze a new baseline only when the change is intentional:
   `--accept-baseline`. Silent dataset changes are rejected by the gate.
