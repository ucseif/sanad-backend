# SANAD shared PPG processing — Phase 4A.1

Standalone offline Python package. No Flutter, Laravel, SQL, HTTP server, AI inference, model tensors or deployment infrastructure. Only the root Git exclusion and this directory were added/changed for this phase. Source dataset stays at `../../datasets/WF-PPG/03`; no original CSV is copied into this package or committed.

## Reproduce

Run from `sanad_backend/processing/ppg` (Python 3.14.3 was used for the recorded run):

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-lock.txt
.\.venv\Scripts\python.exe -m pytest -q
.\.venv\Scripts\python.exe scripts/inspect_dataset.py ../../datasets/WF-PPG/03 --output reports/dataset_inventory.json
.\.venv\Scripts\python.exe scripts/evaluate.py ../../datasets/WF-PPG/03 --split development
.\.venv\Scripts\python.exe scripts/evaluate.py ../../datasets/WF-PPG/03 --split evaluation
.\.venv\Scripts\python.exe scripts/audit_results.py ../../datasets/WF-PPG/03
```

For reusable installation: `python -m pip install -e .` (runtime: NumPy/SciPy); `.[test,evaluation]` adds tests/schema validation and plotting. The exact lock captures the measured Python 3.14 environment; other supported Python versions may resolve different compatible versions through `pyproject.toml` and need their own test run. No global environment or Laravel dependencies were changed.

Tests combine explicitly analytic signals with known QRS/frequency locations and actual supplied-file/provenance/contract checks. The analytic fixtures are not dataset samples or benchmark results. Actual-data checks require the original twelve files at the documented location; no synthetic dataset fallback exists.

## Input and independent reuse

```python
from sanad_ppg import Config, process_recording
from sanad_ppg.dataset import load_recording

time_s, channels = load_recording("../../datasets/WF-PPG/03", session=2)
windows = process_recording(time_s, channels, Config(), recording_id="WF-PPG/03/2")
```

The library consumes a finite, strictly increasing one-dimensional time array in seconds and aligned channel arrays. `PPG_Wrist_G` is required. Red/IR and ECG are optional; missing/nonfinite optional channels cannot fabricate a reference or SpO2. The example uses actual input in place, not a duplicated CSV fixture. At 100 Hz, a full window is approximately 3,000 original observations. Original `Time` values retain experiment-relative offsets; they are not UTC dates.

Future health-data ingestion can map sensor fields into this array contract and call the library. The AI pipeline calls the same function, checks `status/reasons/quality`, and separately prepares its model-specific normalization, features and tensors. It must not reimplement the filter, SQI, peak detection or ratio calculations. Never fill rejected HR with zero/normal values; preserve nulls and rejection reasons. Future raw devices must validate units, polarity, rate and quality thresholds against their own data first. This release is offline: zero-phase filtering uses future samples and is not a ready-made causal BLE/streaming processor.

## Actual dataset inspection

All six preprocessed/indices pairs exist. `reports/dataset_inventory.json` records hashes, columns, row counts, ranges, time statistics, missingness and index/class checks for all twelve files. Recording lengths are 21,037–24,041 rows; no empty or nonfinite numeric values were found. The unnamed CSV column is a row index, not a channel. Shared row coordinates place ECG, wrist channels and reference SpO2 on the same exported time axis; no new ECG/PPG lag alignment is fitted.

The [dataset paper](https://pmc.ncbi.nlm.nih.gov/articles/PMC11790827/) describes prior synchronization and resampling to 100 Hz, time in experiment-relative seconds, and morphology pulse boundaries/classes in the indices files. The actual exports have uneven positive spacing and effective rates near 100.17 Hz; adjacent median spacing varies widely and must not be assumed to be the rate. The original device-clock alignment cannot be revalidated from these exports. The [authors' preprocessing notebook](https://github.com/mehtho/PPG-Preprocessing/blob/main/process.ipynb) documents filtering and AC extraction. These CSVs are **preprocessed recordings, not untouched sensor ADC streams**.

Signal amplitude units/calibration are not encoded in the CSVs: PPG and ECG are treated as export units, not asserted ADC counts or millivolts. Supplied `SpO2` is a pulse-oximeter percentage reference that has passed through dataset preprocessing, not an independent sample-wise invasive gold standard. `PPG_Wrist_G_AC` is already an AC-derived channel; it is inspected but is not confused with raw green or used as a DC source. Red/IR features use the exported DC-bearing channels with the preservation limitations below.

Indices `finger_s/e` and `wrist_s/e` are in-bounds increasing waveform endpoints; `wrist_t/finger_t` contain morphology classes (including 1, 1L, 2E, 2L, 3). They are not ECG R peaks, per-window HR ground truth, or dense validity labels. Endpoint inclusivity is not established by the supplied metadata. They are validated and inventoried, never used to choose evaluation windows, channels or PPG quality thresholds. Unannotated spans are not automatically labelled bad.

## Algorithms and quality

Full parameters are fixed in `PLAN.md`; exact config and source hash are in `reports/frozen_baseline.json`.

- Nonoverlapping 30 s windows; first/last 2 s excluded from HR/features. Tails shorter by more than three configured sample periods are reported rejected, never dropped. Up to three samples of boundary hold handle export-grid rounding; interior gaps beyond 3/fs seconds are rejected. One neighboring observation on either side brackets interpolation. Finite samples are interpolated linearly to the configured uniform grid. Any nonfinite original value invalidates its channel, even if interpolation would skip it. No long missing-data filling or arbitrary sorting.
- Effective rate is `(n-1)/(last-first)`, checked within 10% of configured processing rate; default 100 Hz. Configurable rate is 64–1000 Hz so the ECG passband remains below Nyquist. This is near-rate grid correction, not unrestricted downsampling; no alias-free conversion from a substantially different device rate is claimed.
- PPG: fourth-order Butterworth SOS, 0.5–4 Hz, forward/backward application (effective magnitude response is squared, noncausal). Positive-polarity peaks, minimum spacing 60/max_bpm seconds, prominence 0.5 IQR. Bounded parabolic timing refinement reduces integer-sample RR bias. HR = 60 / median RR. Reject insufficient/implausible peaks, RR CV >0.20, autocorrelation <0.40, spectral concentration <0.45 or peak/spectral HR difference >12 bpm. These independent gates do not inspect ECG or measured SpO2.
- ECG primary: separate third-order 8–25 Hz filter, derivative energy, 120 ms moving average, adaptive threshold and QRS refinement. Cross-check with an independent absolute-amplitude ECG detector, 350 ms refractory and 0.3 export-unit prominence, inspired by the authors' example detector (with absolute polarity handling). Require event F1 >=0.90 within 80 ms and <=5 bpm HR disagreement plus plausible/regular intervals. The fixed amplitude/refractory check can reject low-amplitude or fast ECG and is not universally validated across sensors. This is a derived reference; analytic QRS tests, real dual-detector agreement and reviewed strips validate implementation mechanics, not clinical accuracy. Without annotated ECG, shared detector mistakes remain possible.
- Red/IR: central-window median DC, bandpassed RMS AC and `(red_AC/red_DC)/(IR_AC/IR_DC)`. Reject unavailable/nonfinite/nonpositive DC or absent AC. These are exploratory numeric features; an available ratio does not override a rejected PPG window and is not a calibrated saturation estimate. Previous preprocessing may alter AC/DC. **`spo2_percent` is always null.** No SpO2 calibration, ratio-to-percentage formula or medical validation is implemented.

## Output contract

`output.schema.json` is the JSON Schema 2020-12 contract; `reports/*/sample_output.json` and `windows.jsonl` contain real outputs. Each record has schema/processor versions, recording ID/index, nominal window bounds, central analysis bounds, time basis, processing frequency, `status`, machine-readable `reasons`, nullable `hr_bpm`, engineering `quality`, `ecg_reference`, exploratory `red_ir_features` and null `spo2_percent`. ECG `r_peaks_s` are **window-relative offsets**; add `window_start_s` for recording coordinates. The adapter's optional `dataset_reference_spo2_percent_median` is visibly separate from predictions.

Structural input/config errors raise `ValueError`; no partial results are silently returned. Missing dataset files raise `FileNotFoundError`. Rejected windows are emitted with null HR, never NaN/Infinity or zero-as-a-sentinel. Reason codes include incomplete_window, sampling_rate_mismatch, time_gap, nonfinite_ppg, flat_ppg, flat_filtered_ppg, insufficient_or_implausible_peaks, irregular_pulse_intervals, low_periodicity, diffuse_spectrum, peak_spectrum_disagreement; ECG/features have their own reasons. Future Laravel validation/storage can consume this contract, but no integration is implemented in Phase 4A.1.

See `reports/RESULTS.md` for all measured metrics, coverage denominators and limitations. A small accepted subset with low error must not be marketed as general monitoring accuracy.
