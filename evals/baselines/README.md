# Pairwise baseline outcomes

Place explicitly budgeted blind-Judge evidence here as
`<baseline-id>--<candidate-id>.json`. The CLI accepts only schema
`harness.eval.pairwise.v2`; the former outcome-only v1 format is rejected.

Each v2 envelope must bind the versioned fixture and rubric manifests, fixture
provenance (`synthetic` or `authorized_deidentified`), Writer workflow/model/
prompt/artifact manifests for both variants, a distinct fixed Judge model and
prompt manifest, the seed, fixed Judge budget, per-chapter cost and p95 latency.
Every case needs at least three blind trials with both seeded A/B orders. Each
trial retains only hashes, assignment commitment, label/reveal, bounded
evidence, confidence, cost, and latency; do not store manuscript content here.

Create and validate a no-network execution plan from the versioned example:

```text
php bin/run_pairwise_judge.php --input=evals/cases/pairwise-runner-example.json --seed=41 --plan --json
```

For a live OpenAI-compatible Judge, copy the provider example, set only the
named environment variable (never pass a credential in argv), and choose an
immutable output path:

```text
php bin/run_pairwise_judge.php --input=evals/cases/pairwise-runner-example.json --seed=41 --provider-config=evals/cases/pairwise-provider.example.json --output=evals/baselines/legacy_v1--harness_v2.json --json
```

The runner completes worst-case budget planning before opening the provider
config, rejects actual token/cost overflow without publishing partial evidence,
and self-validates successful output with `PairwiseEvidenceLoader`. A real
provider run remains an explicit external acceptance step.

Run the release evaluation with matching IDs and seed, for example:

```text
php bin/eval.php --suite=pairwise --baseline=legacy_v1 --candidate=harness_v2 --seed=23 --annotations=calibration_2026_08 --json
```

The release gate requires net win rate of at least +10pp with a non-negative
bootstrap 95% CI lower bound, no category whose CI upper bound is negative,
cost/chapter increase at most 15%, p95 latency increase at most 20%, and valid
human calibration. Missing human evidence produces a report with
`release_pass=false`; it is never replaced with synthetic scores.
