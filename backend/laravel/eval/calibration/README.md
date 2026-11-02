# Judge calibration labels

`human_labels.jsonl` is an initial calibration set for `--calibrate=...`.
`labeled_by: author` means the labels were assigned by the engineer who built
the dataset while checking the source chunks — they are **not** independent
double labels. Before treating kappa as a quality signal, have a second person
label a random 20% sample without seeing judge output and replace/extend this
file.

Fields:

- `id`: must match `golden.jsonl`
- `faithfulness`: 1 if the answer's claims are supported by the retrieved
  context, 0 otherwise
- `correctness`: 1 if the answer agrees with `expected_answer` (or correctly
  refuses), 0 otherwise
- `labeled_by`, `notes`: provenance
