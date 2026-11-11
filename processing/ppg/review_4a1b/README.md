# Phase 4A.1B review workspace

Read [REPORT.md](REPORT.md) for measured results, root causes, sensor compatibility, streaming design and a new-data protocol. This folder adds research/audit code only. **No revised processor was justified or released.** The original `sanad-ppg/0.1.1` source, schema, tests and reports remain byte-for-byte unchanged.

From `sanad_backend`, using the existing isolated environment:

```powershell
& './processing/ppg/.venv/Scripts/python.exe' processing/ppg/review_4a1b/run_review.py datasets/WF-PPG/03
& './processing/ppg/.venv/Scripts/python.exe' processing/ppg/review_4a1b/development_experiments.py datasets/WF-PPG/03
& './processing/ppg/.venv/Scripts/python.exe' processing/ppg/review_4a1b/retrospective.py datasets/WF-PPG/03
& './processing/ppg/.venv/Scripts/python.exe' processing/ppg/review_4a1b/audit_plots.py datasets/WF-PPG/03
& './processing/ppg/.venv/Scripts/python.exe' -m pytest processing/ppg/tests processing/ppg/review_4a1b/tests -q
```

No new dependency or environment installation is needed. Scripts read dataset CSVs in place and write only under this folder. Do not run the old baseline evaluation/audit scripts as part of this review: they write to original reports. Re-running this review verifies preserved-file hashes and the current development decision/code before writing its own artifacts. A changed code/configuration decision requires an explicitly documented revision; never delete the original baseline manifests to bypass the check.

## Artifacts

- `artifacts/preservation.json`: SHA-256 of 113 original baseline/data/Laravel/configuration files, including all twelve original CSVs.
- `artifacts/development`: unchanged-gate channel comparison, all research candidate comparisons/windows, explicitly diagnostic one-gate sensitivity, rejection evidence, filter/time diagnostics and actual waveform plot. All tuning/selection uses sessions 0–1 only.
- `artifacts/development_decision.json`: frozen no-release decision, processing constants and research-code hashes. `development_decision_pre_reference_guard.json` preserves the decision before a fail-closed validation fix; measured comparisons are identical.
- `artifacts/retrospective`: descriptive comparisons on already inspected sessions 2–5. These are **not untouched holdout results** and cannot promote a candidate.
- `analysis.py`: explicit real-channel research adapter, preserved-file checks, metric arithmetic and post-hoc diagnostics.
- `candidates.py`: isolated spectral-guided peak experiments and fail-closed development selection. Reuses baseline filtering/input validation/RR/timing/spectral evidence. Not an ingestion library or a new deployment artifact.
- `audit_plots.py`: display-only normalization and contextual filtering/time sensitivity. Does not change processor inputs or output claims.

Channel comparisons use review-specific records with extra `review_channel`, `review_session` and `diagnostics` metadata; they are research artifacts, not a silent change to `output.schema.json`. ECG-assisted diagnoses and the 3 bpm error screen are offline analysis only; no predicted SpO2 is generated. Full per-window errors and all rejections remain inspectable rather than being hidden by conditional aggregate metrics.
