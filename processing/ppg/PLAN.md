# Phase 4A.1 implementation and verification ledger

Scope: standalone Python library, dataset adapter, tests, evaluation and contract. No Flutter writes, Laravel code changes, database calls, servers or dataset copies. Laravel-specific installation bootstrap is inapplicable to this independent module.

1. Verify the twelve supplied files, checksum them, inspect time spacing, ranges, missingness and morphology indices. Preserve original files; exclude dataset and local Python environment from Git.
2. Write analytic unit tests first (clearly labelled synthetic mathematical fixtures, never benchmark data). Implement input/window validation, timestamp-aware interpolation, Butterworth filtering, deterministic PPG HR/quality, independent ECG reference cross-check and exploratory AC/DC features.
3. Evaluate sessions 0–1 as development only, freeze configuration, then run sessions 2–5 once for held-out evaluation. Report all eligibility/rejection counts and denominator definitions, MAE/RMSE on paired valid windows, ECG detector agreement and full per-window output. Do not use ECG/SpO2/indices to select PPG channels or reject PPG windows.
4. Document reproducibility, actual measured results, schema, sampling/alignment limitations, reference uncertainty and model-independent reuse. Run all tests, validate the output contract and request independent read-only review.

## Frozen baseline before HR evaluation

- Wrist green (not green-AC) is the fixed HR channel. Uniform processing grid 100 Hz; validate effective input rate within 10%, max time gap 30 ms, finite strictly increasing seconds. No sorting, collapsing duplicates, long-gap filling or sensor-stream realignment.
- Nonoverlapping 30 s windows, report incomplete tail as rejected. Zero-phase fourth-order Butterworth SOS PPG bandpass 0.5–4 Hz; ECG third-order 8–25 Hz. Exclude first/last 2 s of each full window from HR/features to limit edge transients. Offline processing only.
- PPG peaks: positive polarity, minimum separation 1/3 s, prominence 0.5 IQR; median RR HR 40–180 bpm, >=8 peaks. Reject RR CV >0.20, normalized autocorrelation <0.40, spectral concentration <0.45 (±0.2 Hz around dominant cardiac frequency), peak/spectrum disagreement >12 bpm. These are engineering gates, not a calibrated medical SQI.
- ECG primary: derivative-squared energy, 120 ms smoothing, adaptive robust threshold, 280 ms refractory, QRS-location refinement. Independent check: absolute ECG amplitude peaks, 350 ms separation, prominence 0.3 in exported units (authors' example prominence). Require bidirectional event matching within 80 ms >=0.90 and HR difference <=5 bpm; RR plausibility/regularity are checked separately. Never validate the ECG against PPG agreement.
- Red/IR DC = median exported signal; AC = RMS same PPG bandpass, central window. Emit AC/DC and ratio only as uncalibrated exploratory features. Always emit `spo2_percent: null`; supplied SpO2 remains a dataset reference measurement, not an HR label or a calibration target here.
- ECG detector tests use known analytic QRS locations; real-record detector agreement and saved ECG strips provide an additional audit, not annotated-R-peak or clinical validation. No manual ECG ground-truth annotations are supplied.
- Analytic tests exposed integer-sample median-RR bias at 170 bpm (35/36-sample intervals). Added bounded three-point parabolic peak timing refinement for PPG and ECG before any dataset HR evaluation; no thresholds were changed. Nonfinite samples invalidate their channel even if interpolation would otherwise skip them.

## Initial inspection

All 12 CSVs exist and are untracked. Each recording has 21,037–24,041 rows, ~210–240 s duration, global effective rate ~100.17 Hz and strictly increasing but uneven timestamps. Median adjacent spacing is not a reliable rate estimator here (session 0 median ~4.47 ms despite ~100.17 Hz overall). Both ECG and PPG share the same row/time grid. Morphology indices are valid in-bounds pulse intervals, not R-peak labels. Full observed inventory is in `reports/dataset_inventory.json`.

## Independent review / API guard patch

Review found no dataset-path blocker and one short-array public-helper validation issue. Six explicit short/nonvector regression cases were added (four initially failed). Version 0.1.1 adds structural checks before filtering; all algorithm parameters, split membership, quality gates and complete-window numerical operations are unchanged. The pre-patch frozen manifest is retained as `reports/frozen_baseline_pre_api_guard.json`. Development and evaluation are rerun to verify identical aggregate metrics rather than using evaluation scores to alter parameters. This is reproducibility validation after a guard-only correction, not an untouched second holdout.
