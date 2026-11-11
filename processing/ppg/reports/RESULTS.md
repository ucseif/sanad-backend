# Phase 4A.1 — measured WF-PPG results

Recorded implementation: `sanad-ppg/0.1.1`. This is an offline, single-subject engineering evaluation, **not medical validation**. Data: the twelve supplied CSVs in `datasets/WF-PPG/03`, read in place. No dataset samples or benchmark scores were fabricated.

## Evaluation protocol

Session IDs 0–1 were declared development data and 2–5 held-out evaluation data before HR results were calculated. Parameters/channel/quality gates were fixed in `PLAN.md`; evaluation is guarded by matching the configuration and source hash against `frozen_baseline.json`. No channel selection, threshold tuning or lag fitting used ECG performance on the evaluation sessions. This is recording-disjoint but **not subject-disjoint**: all six recordings belong to subject 03. The numbering is only the supplied session identifier, not an assumed activity or severity order.

After independent review, an input guard was added for direct short/nonvector calls to public signal helpers, with no changes to processing parameters or valid-window calculations. The original frozen manifest is preserved in `frozen_baseline_pre_api_guard.json`. Both splits were recomputed after that guard-only patch and their complete aggregate dictionaries compared exactly: unchanged. This repeat is a reproducibility check, not a new untouched holdout.

The fixed baseline uses green PPG, 30-second nonoverlapping nominal windows and central 26-second analysis. It is deliberately conservative about unreliable waveform windows. Indices are checked but do not select or label evaluation windows. ECG validity is evaluated independently, without considering the PPG estimate. The reference is derived ECG HR, cross-checked by a second ECG detector, not supplied R-peak annotations.

## Main measured results

| Split | Windows | PPG accepted | PPG rejected | Valid ECG | Paired coverage | MAE (bpm) | RMSE (bpm) | Bias (bpm) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Development: 0–1 | 16 | 11 | 5 | 16 | 68.75% | 0.322072 | 0.410121 | -0.032655 |
| Evaluation: 2–5 | 31 | 6 | 25 | 31 | **19.35%** | **0.638829** | **0.718232** | +0.283599 |

MAE = mean absolute PPG-minus-ECG HR error; RMSE = square root of mean squared error; bias = mean signed error. All three use only windows with both accepted PPG and valid ECG. Rejected windows do **not** receive zero error, imputed HR or normal labels. Coverage is paired accepted windows divided by all nominal windows. Every ECG window passed here, so paired coverage against valid ECG and PPG-only coverage have the same numerical value. The JSON summaries explicitly store all denominators.

**The low coverage is the main result.** The small error on six accepted evaluation windows does not establish accuracy across the other 25 windows, and does not establish continuous monitoring suitability. Each window excludes four seconds of edge context: the six accepted central spans cover 156 seconds out of 930 nominal evaluation seconds (16.77% if expressed against the full acquisition-window duration). Window coverage and continuous-time coverage are different quantities.

### Evaluation by recording

| Session | Windows | Accepted | Rejected | Coverage | MAE (bpm) | RMSE (bpm) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 2 | 8 | 1 | 7 | 12.50% | 0.439650 | 0.439650 |
| 3 | 7 | 4 | 3 | 57.14% | 0.665216 | 0.768985 |
| 4 | 8 | 1 | 7 | 12.50% | 0.732458 | 0.732458 |
| 5 | 8 | 0 | 8 | **0.00%** | null | null |

Session 5 has no usable PPG HR estimates under this baseline. Its accuracy is undefined, not zero error. Sessions with one accepted window do not support a reliable session-level accuracy claim.

### Rejection and reference audit

Evaluation rejection flags: irregular pulse intervals **21**, low periodicity **21**, diffuse spectrum **6**, peak/spectrum disagreement **5**. Flags overlap; their sum is not a count of unique rejected windows. Unique rejected PPG windows: **25**. All actual numeric signals were finite; there were no time-gap/rate/flat-signal/incomplete-tail rejections in this supplied dataset. Those paths are covered by analytic unit tests. The final few milliseconds of export-grid discrepancy are within the documented three-sample boundary tolerance.

ECG: **0 invalid reference windows** in either split. Primary/alternate event counts agree at **579 development events** and **1,108 evaluation events**, after edge exclusion. Minimum and mean per-window event F1 against the alternate detector are both **1.0**, using one-to-one matching within 80 ms. This is detector agreement on the same ECG signal, not sensitivity/specificity against manually annotated truth; both algorithms can share systematic mistakes. Known-location analytic QRS tests cover 45, 72, 120 and 170 bpm. `ecg_audit.png` shows fixed first-window 4–12 s strips from sessions 0, 2 and 5, reviewed for gross peak-placement issues. This visual spot check is not a clinical or full-record manual annotation exercise.

`audit_results.py` independently recomputed errors/coverage from `windows.jsonl` and verified all twelve source hashes remain unchanged; see `result_audit.json`. All 47 actual output rows pass the JSON Schema. Full exact-precision metrics, per-recording counts, ECG peak offsets and SQI values are retained in each split's `summary.json` and `windows.jsonl`.

## Sampling, units and alignment observations

| Session | Rows | Duration (s) | Effective Hz | Median adjacent dt (ms) | Largest adjacent gap (ms) | Index rows |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 0 | 24040 | 239.975426 | 100.172757 | 4.472082 | 20.474859 | 186 |
| 1 | 24041 | 239.986883 | 100.172141 | 9.956627 | 13.663186 | 129 |
| 2 | 24041 | 239.986519 | 100.172293 | 9.949488 | 13.714122 | 169 |
| 3 | 21037 | 209.994811 | 100.173904 | 9.943696 | 14.192641 | 220 |
| 4 | 24040 | 239.982219 | 100.169921 | 7.138185 | 15.284349 | 148 |
| 5 | 24040 | 239.982314 | 100.169882 | 5.822053 | 18.059568 | 99 |

All timestamps increase, all relevant arrays have equal length, and all supplied waveform indices are ordered and in bounds. No missing/nonfinite numeric entries were observed. A single global nominal rate is insufficient to describe the individual timestamp jitter. No row-index-as-time substitution or median-dt rate estimate was used. Shared export row alignment is verified; original device-clock synchronization and unknown residual physiological delay cannot be proven from these files alone.

Time is used as experiment-relative seconds. ECG/PPG amplitudes remain in unspecified export units. The supplied SpO2 reference lies approximately between 95.9911% and 99.2719% across these files; it is kept only as reference metadata and is never used to choose HR/SQI parameters or generate a predicted saturation. This narrow, single-subject reference range cannot support clinical SpO2 calibration.

## Limitations and next work outside this phase

- Only one participant, approximately 23.5 minutes, non-independent adjacent windows, small accepted held-out subset and very uneven per-session coverage. No population-, disease-, device- or motion-generalization result is established. No confidence interval or clinical accuracy claim is asserted.
- These are processed and previously aligned/resampled exports, not original sensor ADC recordings. Original analog scaling, clipping, missing segments, hardware timestamps and transfer characteristics cannot be reconstructed. Filtered/DC/AC amplitudes may already have been changed by preprocessing; actual raw-device ingestion needs separate validation.
- Quality rejection is heuristic engineering logic. Real irregular rhythms can be rejected; regular motion artifacts can sometimes pass. Rejection does not mean normal physiology, absence of disease, or a valid downstream AI input. No arrhythmia detector is implemented.
- ECG reference is algorithm-derived. Its independent amplitude detector uses export-specific prominence and refractory assumptions; clinical annotations or an independently labelled ECG benchmark would be needed for stronger validation. Event agreement alone is insufficient.
- Red/IR ratios are exploratory and uncalibrated; **every output has `spo2_percent: null`**. No saturation accuracy metric, ratio-to-SpO2 conversion or cross-subject calibration is provided.
- Zero-phase window processing is offline and uses future samples. No latency, BLE streaming, Python service, Laravel ingestion, AI model normalization, Flutter interface or Phase 4A.2 work is included.

## Verification and artifacts

**34 automated tests passed**: filtering/DC/noise attenuation, analytic PPG and known-QRS peak locations, sub-sample timing, timestamp validation, rate/gap/missing/flat/noise rejection, optional sensor handling, helper input guards, tail outputs, JSON contracts for actual and rejected records, original file checksums, disjoint session split and independent metric arithmetic. Python source/scripts compile successfully. The independent read-only reviewer confirmed no remaining Critical/Important findings, and the helper edge case was fixed with observed failing-then-passing tests.

Files: shared library under `src/sanad_ppg`, analytic/data/contract tests under `tests`, three reproducibility/audit scripts plus inspection under `scripts`, schema at `output.schema.json`, documentation at `README.md`, exact environment at `requirements-lock.txt`, and measured inventory/manifest/output/summary/audit artifacts under `reports`. Dataset and environment are Git-excluded; original dataset has zero tracked files. No Laravel/auth/link/migration code or Flutter files were changed, and no database or external notification operations were executed.
