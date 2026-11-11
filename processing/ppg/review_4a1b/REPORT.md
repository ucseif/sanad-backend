# Phase 4A.1B — PPG coverage and sensor compatibility review

**Decision: keep the frozen `sanad-ppg/0.1.1` baseline unchanged. No revised processor is released.** The tested conservative changes do not improve Red/IR coverage on development data. This is a review with research-only candidate code, not MAX30102 validation, medical accuracy or SpO2 validation.

All additions are under `processing/ppg/review_4a1b`. A before/after manifest verifies **113 original files**, including baseline source/manifests/reports/schema/tests, all twelve CSVs, Laravel application/config/routes/migrations and the existing Git exclusion. Nothing in Flutter was modified or generated. No database, deployment, hardware driver or API integration was run.

## Protocol and interpretation

Sessions **0–1** remain the development set for this review. Previously inspected sessions **2–5 are retrospective data**, never described here as an untouched holdout. Baseline channel comparisons were measured first. Three peak-selection variants and six one-gate sensitivity settings were defined in `PLAN.md`; candidate selection used development results only. `artifacts/development_decision.json` was frozen before retrospective comparisons. No signal parameter was changed in response to retrospective results.

Every channel uses the same actual timestamps, 30 s windows, central 26 s analysis, frozen filter, independent ECG reference and original quality gates. The research adapter explicitly maps the named real source channel into the old green-named entrypoint; it records `review_channel` and never mutates the input arrays. That adapter is **not** a claim that MAX30102 provides green, or a production-compatible source-channel API.

Coverage = accepted PPG windows / all available nominal windows for that channel. MAE/RMSE use only accepted windows with valid ECG. Every reference window is valid in these recordings, so paired and accepted counts coincide. With zero accepted windows, errors are **null/undefined**, not zero. Rejected windows are retained with reasons and ungated diagnostics; their estimates are not published as usable HR.

## Green vs Red vs IR — actual development comparison

| Channel | Accepted / total | Coverage | Rejected | MAE (bpm) | RMSE (bpm) | Worst accepted error (bpm) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Green | 11 / 16 | 68.75% | 5 | 0.322072 | 0.410121 | 0.755405 |
| Red | 0 / 16 | **0.00%** | 16 | null | null | null |
| IR | 0 / 16 | **0.00%** | 16 | null | null | null |

The current algorithm does not demonstrate usable Red/IR HR coverage in the supplied development recordings. There is no accepted-window evidence for choosing Red over IR or IR over Red. This result does not prove either wavelength is inherently unsuitable: sensor design, optical coupling, pressure, gain, preprocessing and signal polarity can differ across datasets/devices.

The original Green development and retrospective outputs were reproduced window-for-window, including HR, status, reasons, quality and ECG fields. Original reports were read, never overwritten. See `artifacts/development/baseline_summary.json`, `baseline_windows.jsonl` and the preservation tests.

## Root causes of low coverage

The diagnostic labels below are **post-hoc engineering hypotheses**, not independent waveform-quality ground truth. They use ECG-derived HR only to examine plausible periods/error after the processor has made its decision. Neither a candidate nor a production quality gate receives ECG, SpO2, morphology labels or these diagnostic categories.

| Rejected-window evidence | Green development (5 rejected) | Green retrospective (25 rejected) | Interpretation |
| --- | ---: | ---: | --- |
| Weak/distorted periodic structure | 4 | 17 | Weak autocorrelation at the ECG-derived period plus dispersed spectrum or irregular detected intervals; consistent with waveform degradation/artifact domination, but physical cause is unverified |
| Potential near-threshold over-rejection | 0 | 5 | Only one near-threshold gate failed; ungated median HR is within 3 bpm of derived ECG. This is a review flag, not permission to accept |
| Mixed/unresolved | 1 | 3 | Detector errors, amplitude variation, morphology and gates cannot be cleanly separated |
| Detector-only rule met | 0 | 0 | No case met the strict rule of wrong peak HR but good spectral/ECG-period evidence; this does not mean no missed/extra peaks exist |

All 16 rejected Red windows and all 16 rejected IR windows in development show weak/distorted-period evidence under the same diagnostic rules. Red RR CV ranges **0.279–0.504**, IR **0.233–0.571** (baseline maximum 0.20). Red autocorrelation at the ECG-derived period ranges **-0.450 to 0.262**, IR **-0.392 to 0.249** (baseline periodicity minimum 0.40). Thus rejection is not explained by a lone borderline threshold or by an incorrect HR lag alone. These are observations of the supplied processed signals; they cannot distinguish poor contact from preprocessing artifacts or establish raw sensor SNR.

**Concrete mixed case:** development session 1/window 2 has 31 detected PPG peaks versus 36 ECG QRS events in the central span. PPG gaps reach **2.191 s**, with RR CV **0.392**, while its median HR is 83.490 bpm versus derived ECG 82.258 bpm. The waveform has a low-amplitude stretch where pulses are missed. Accepting because the median happens to be close would conceal missing pulse information. Suppressing extra peaks cannot recover these missing events.

**Near-threshold cases, descriptive only:** retrospective Green session/window 2/0 and 2/3 fail ACF at **0.354** and **0.382**; 2/1, 3/0 and 3/3 fail RR CV at **0.214**, **0.224** and **0.208**. Their ungated errors are approximately **1.307, 2.118, 1.211, 0.655 and 0.109 bpm**, respectively. These values were not used to select new thresholds. A close median does not certify reliable waveform morphology, individual beat timing or safety.

**Session 5:** all eight Green windows remain rejected, with weak/distorted-period evidence. Ungated errors range **0.190–31.484 bpm**; four happen to be below 3 bpm. Neither that coincidence nor low average error on a selected subset justifies publishing the other estimates. Red/IR also accept zero of its eight windows.

### Checks for alternative explanations

- No input time-gap/rate/missing-value rejection explains this coverage loss; those signals are finite on the exported aligned grids.
- Development-only uniform-row-time interpretation accepts the same number of windows: Green 7/8 in session 0 and 4/8 in session 1, Red/IR 0/8 in each. This is a sensitivity check, not replacement of the supplied `Time` values; source clock semantics cannot be reconstructed here.
- Filtering each window versus using full-record context changes central filtered waveforms: median normalized RMS differences are **1.14% Green**, **2.31% Red**, **2.24% IR**; maxima are **8.25%, 23.24%, 11.68%**. Boundary/context effects exist, especially for some Red windows, and warrant future causal-filter validation. This diagnostic does not establish that full-context filtering would recover reliable windows; it was not adopted or tuned.
- `artifacts/development/representative_waveforms.png` shows the first accepted, weak/distorted and mixed Green cases, with all three filtered PPG channels and independent ECG. Peak markers and gray excluded edges expose uneven/missing pulses. Scaling by each trace's central SD is for display only, not a model normalization or cross-channel amplitude/SNR comparison.

## Conservative peak and quality experiments

Research candidates use the frozen bandpass, prominence, fractional peak timing, RR calculation and spectral evidence. Their minimum peak separation is the larger of the baseline refractory and **0.55, 0.65 or 0.75** of the dominant spectral period. All original gate thresholds remain fixed. Additional conservative checks reject inconsistent peak counts and strong half-frequency/double-period ambiguity; no ECG is consulted.

| Development method | Green accepted | Green MAE (bpm) | Green RMSE (bpm) | Red accepted | IR accepted |
| --- | ---: | ---: | ---: | ---: | ---: |
| Frozen baseline | 11/16 | 0.322072 | 0.410121 | 0/16 | 0/16 |
| Guided 0.55 | 11/16 | 0.308552 | 0.378691 | 0/16 | 0/16 |
| Guided 0.65 | 11/16 | 0.331043 | 0.392059 | 0/16 | 0/16 |
| Guided 0.75 | 11/16 | 0.379324 | 0.435009 | 0/16 | 0/16 |

No variant gains or loses an accepted development window. A small change in Green's conditional error, on the same 11 windows from one subject, is insufficient justification for a release targeted at Red/IR hardware.

One-gate-at-a-time sensitivity, **development only**: RR CV 0.15 reduces Green acceptance to 10/16; RR CV 0.25, ACF 0.30 or 0.50, and concentration 0.35 or 0.55 each leave Green at 11/16. Red/IR remain 0/16 for every tested setting. Loosening only one threshold therefore does not solve development rejection; simultaneously loosening several gates was not pursued to manufacture coverage. Sensitivity settings are explicitly excluded from promotion.

Promotion required a gain on hardware-compatible channels, no loss of prior accepted windows, no increased worst error or newly accepted error >3 bpm, and valid ECG evidence for every accepted candidate window. Where baseline Red/IR has no errors to compare, 3 bpm is only a predeclared engineering screen—not a medical tolerance. No candidate qualifies. Research functions remain isolated; the original processor/API/schema/version are unchanged.

## Retrospective comparison — not a fresh holdout

| Channel, sessions 2–5 | Accepted / total | Coverage | MAE (bpm) | RMSE (bpm) |
| --- | ---: | ---: | ---: | ---: |
| Frozen Green | 6/31 | 19.35% | 0.638829 | 0.718232 |
| Frozen Red | 0/31 | 0.00% | null | null |
| Frozen IR | 0/31 | 0.00% | null | null |

All three guided variants produce the same accepted counts on these recordings, and the same Green MAE/RMSE as the frozen baseline; none gains a Red/IR window. These descriptive comparisons support no new release claim and do not restore independent evaluation status. Full comparisons, per-window records, rejection flags and unchanged development decision are under `artifacts/retrospective`.

## MAX30102 compatibility

The [MAX30102 datasheet](https://www.analog.com/media/en/technical-documentation/data-sheets/max30102.pdf) documents Red/IR channel data, configurable sampling/LED settings, FIFO overflow behavior and on-chip sample averaging/decimation. It supplies no Green channel. Programmed sample rate alone is insufficient: the effective FIFO output rate depends on averaging. Capture mode, LED slot order, ADC range/resolution, pulse width/current, averaging, overflow counters and sample sequence/timestamps must accompany real data. The current processor requires at least 64 Hz; a lower effective rate cannot be disguised as 100 Hz.

WF-PPG used a different sensor, MAXM86161, with a controlled wrist pressure-clamp setup and subsequent preprocessing, as described in the [dataset paper](https://pmc.ncbi.nlm.nih.gov/articles/PMC11790827/). Shared Red/IR labels do not establish equivalent optics, ADC scaling, contact mechanics or noise characteristics. No untouched MAX30102 ADC stream was supplied or tested. This review therefore cannot choose a validated MAX30102 channel or claim device compatibility from WF-PPG alone. The frozen full-record entrypoint still requires Green; its generic low-level helper can be studied with Red/IR arrays, but the demonstrated coverage here is inadequate. A future version needs explicit source-channel/polarity metadata and actual-device evidence, rather than silently relabelling Red as Green.

Red/IR ratio features remain uncalibrated and are not a solution to poor HR quality. All outputs retain null predicted SpO2; no saturation accuracy or calibration experiment was conducted.

## Transition to real-time processing — design only

1. Establish the actual effective sample/frame rate from sensor configuration and device sample counters. Keep monotonic acquisition time, per-slot channel identity and overflow/loss markers; reception or future transport arrival time is not the sensor clock. Reject discontinuities instead of interpolating through dropouts or joining different acquisitions.
2. Replace `sosfiltfilt` with a designed **causal** filter, carrying SOS state between chunks. Forward/backward and causal magnitude/phase responses differ; the old filter/threshold metrics do not transfer automatically. Reset state on gaps/reconfiguration and emit warm-up status until transients settle. Test chunk-size invariance against a causal full-sequence reference.
3. Use a bounded ring buffer and time-aware online resampling with explicit jitter, rate-change and anti-alias rules. The current bracketing interpolation can use a sample beyond a nominal window boundary; a streaming design must declare any buffering/lookahead and label analysis bounds honestly.
4. Use trailing windows with explicit initial acquisition latency and update cadence. A 30 s window needs about 30 s before its first full result; median-RR and peak confirmation introduce further reporting delay. Record the last analyzed acquisition time and age, not an invented instantaneous HR. Account for overlap when computing coverage and confidence intervals.
5. Rework peak confirmation, trend handling and SQI for causal state, partial beats and local signal changes. Do not use ECG to choose a channel/gate online. Any Red/IR consensus or adaptive threshold requires independent validation; agreement can also reflect common motion artifacts.
6. Version the configuration, source-channel and time/status contract; test replay of raw device traces, irregular chunks, gaps, startup/reset, off-skin, clipping and stale outputs. Validate performance prospectively before replacing the offline baseline. No streaming, BLE, Laravel health API or Flutter code is implemented in this review.

## New independent evaluation data

- Collect actual MAX30102 raw Red/IR and complete firmware/configuration metadata in the intended wrist mounting. Retain contiguous counters/timestamps and overflow/dropout events; do not only save a filtered or selected good waveform. Keep anonymized participant/session IDs and consent appropriate to the graduation study.
- Acquire a separately synchronized ECG reference. Independently review R peaks and ECG quality; annotate poor/off-skin/contact/motion intervals without seeing candidate HR errors. Distinguish true beat irregularity from sensor artifact. Reference validity and PPG quality must remain separate.
- Cover intended activities/contact variation, ambient conditions, wrist/skin/perfusion variation, repeated visits and device units. Keep failure periods in the denominator. WF-PPG subject 03 and all sessions used here belong to development/retrospective analysis from now on.
- Partition by participant, visit and device before any tuning. Freeze code, parameters, selection rules, coverage definitions and rejection policy on development data, then evaluate a new sealed participant/device-disjoint set once. Choose study size and recording duration with the supervisor based on desired uncertainty/coverage estimates; this review cannot establish a clinically powered sample size.
- Predeclare per-channel and per-participant MAE/RMSE, coverage, invalid/rejected counts, error tails/worst cases, failure reasons and false acceptance of annotated poor signals. Report participant-clustered uncertainty rather than treating overlapping windows as independent. Include every acquisition and report unavailable estimates explicitly.

This is an HR/signal-quality collection recommendation. It does not validate SpO2, diagnostic use or medical accuracy.

## Verification and boundaries

Combined original and review tests: **63 passed**. Checks cover preserved files, exact baseline reproduction, actual comparative metric arithmetic, null/coverage denominators, no retrospective promotion, one-gate sensitivity, known-frequency pulses, dicrotic/second-harmonic ambiguity, noise/input rejection and candidate eligibility. Independent review identified an eligibility edge case for windows without valid ECG; a failing regression preceded a fail-closed fix. All actual references were valid, and exact development/retrospective comparisons and the no-release decision were unchanged. The pre-fix review manifest remains in `artifacts/development_decision_pre_reference_guard.json`; this was a selection-safety correction, not signal tuning or a new holdout.

See `README.md` for exact commands and `PLAN.md` for the investigation sequence. No original baseline report, source file, schema or frozen manifest was changed; no dataset was duplicated or committed; existing Laravel and unrelated work remain intact.
