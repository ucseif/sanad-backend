# Phase 4A.1B — coverage and sensor compatibility review

Scope: only new files under this directory. Baseline source, frozen manifests, schema, tests and reports are immutable and checksummed before/after. No Flutter, Laravel, migrations, database, BLE, server, dataset copy or dependency change.

## Investigation protocol (before new channel results)

1. Hash all pre-existing module artifacts and all 12 dataset files. Reproduce baseline diagnostics without overwriting baseline outputs. Compare unchanged gates across Green, Red and IR on development sessions 0–1 (16 windows/channel).
2. For rejected windows, retain pre-rejection peak HR, spectral HR, RR distribution/count and autocorrelation at both detected period and ECG-derived reference period. ECG-assisted fields are post-hoc diagnostics only: never inputs to a processor or gate. Examine waveform/peak plots. Separate evidence of peak errors, potential gate over-rejection and weak/distorted periodicity; do not claim artifact ground truth without independent annotations.
3. Development-only exploration: spectral-guided peak separation at 0.55, 0.65 and 0.75 of the dominant period, never below the baseline refractory. Keep all original SQI thresholds; additionally require observed peak count consistent with the estimated HR within 15% plus one beat. Compare against the baseline on the same channels. Flag newly accepted errors >3 bpm as an engineering reliability concern, not a clinical tolerance.
4. Development-only one-gate-at-a-time sensitivity: RR CV 0.15/0.25 vs 0.20, ACF 0.30/0.50 vs 0.40, concentration 0.35/0.55 vs 0.45. These are explicitly diagnostic experiments, not silent production changes or a license to promote a looser gate.
5. Choose at most one candidate rule using development results only. Promotion requires increased accepted-window count on the available Red/IR channels, no loss of a previously accepted development window, no increase in worst accepted error or newly accepted error >3 bpm on either hardware-compatible channel, and passing analytic regression tests. Otherwise release no revised processor; retain candidates as research only.
6. Freeze the decision and candidate code/config before retrospective analysis of sessions 2–5. Those recordings were inspected in Phase 4A.1 and are NOT an untouched holdout for this work. Report all channel/candidate counts and newly accepted errors, including failures. No post-retrospective retuning.
7. Recommend new participant/device-disjoint, prospectively locked evaluation and a streaming transition design; implement neither streaming nor integration.

Before candidate evaluation, analytic tests additionally require rejection when a strong second harmonic has a meaningful half-frequency component (power >=20% and double-period ACF larger by >0.10). This is an added conservative ambiguity gate, not relaxation of an original threshold. Candidate constants are recorded before candidate metric results. If baseline Red/IR accepts zero windows, its worst error is undefined; a candidate must still keep every accepted/new window within the predeclared 3 bpm engineering screen. That screen is not a medical performance standard.

## Review correction (no signal tuning)

Independent review found that candidate eligibility could incorrectly treat a new accepted window without valid ECG as evidence of gain. The regression failed before adding a fail-closed `accepted == paired` requirement. All actual references were valid and no candidate was selected, so no measured result or release decision changes. The pre-correction review decision is retained as `artifacts/development_decision_pre_reference_guard.json`; development/retrospective results are recomputed and compared exactly for reproducibility. No algorithm threshold, spectral/peak rule, split membership or diagnostic category was changed after viewing retrospective results. This is a selection-safety bug fix, not another holdout.

Evidence, metrics and decisions are saved beside this plan. A single-subject development improvement is insufficient evidence of medical accuracy, MAX30102 validation or SpO2 calibration.
