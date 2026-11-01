# Human annotation evidence

Human calibration requires two independent blind labels per pair and a third
adjudicator for conflicts. Store only authorized or synthetic case IDs and
labels; never copy private manuscript text. The Harness computes weighted
Cohen kappa and exact judge/human agreement from aligned labels.

Files are named `<safe-id>.json` and use schema
`harness.eval.annotations.v1`. The envelope must contain exactly
`schema`, `pairwise_source_hash`, `rubric_hash`, and `annotations`. Both hashes
bind the labels to the validated pairwise v2 evidence. Every annotation row
contains `pair_id`, two independent `{blind_id,label}` values, an independent
adjudicator value only when they disagree, and the immutable `judge_outcome`.

At least 30 pairs or 20% of all pairwise trials (whichever is larger) must be
annotated. Release requires weighted Cohen kappa >= 0.60 and exact
Judge-human agreement >= 0.70. Omitting `--annotations`, or naming an evidence
file that does not exist, keeps both scores null and fails the release gate;
invalid or unbound evidence is rejected.
