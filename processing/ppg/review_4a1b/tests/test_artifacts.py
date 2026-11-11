from pathlib import Path
import hashlib
import json
import numpy as np
import pytest
from analysis import verify_preserved,CHANNELS

HERE = Path(__file__).resolve().parents[1]
ARTIFACTS = HERE/'artifacts'


def load_rows(path): return [json.loads(line) for line in path.read_text().splitlines()]


def test_all_original_source_reports_dataset_and_laravel_files_remain_unchanged():
    frozen = json.loads((ARTIFACTS/'preservation.json').read_text())
    assert verify_preserved(HERE.parent.parents[1],frozen) == 113
    assert sum(name.startswith('datasets/') for name in frozen) == 12
    assert any(name.endswith('reports/frozen_baseline.json') for name in frozen)
    assert any(name.endswith('reports/RESULTS.md') for name in frozen)


def test_baseline_green_reproduction_matches_each_original_window():
    for split,oldsplit in [('development','development'),('retrospective','evaluation')]:
        old = load_rows(HERE.parent/'reports'/oldsplit/'windows.jsonl')
        new = [r for r in load_rows(ARTIFACTS/split/'baseline_windows.jsonl') if r['review_channel']=='Green']
        assert len(new)==len(old)
        for a,b in zip(old,new):
            for field in ['recording_id','window_start_s','window_end_s','status','hr_bpm','reasons','quality','ecg_reference']:
                assert a[field] == b[field]


@pytest.mark.parametrize('split,n',[('development',16),('retrospective',31)])
def test_channel_metrics_independently_recompute_real_results(split,n):
    rows = load_rows(ARTIFACTS/split/'baseline_windows.jsonl')
    summary = json.loads((ARTIFACTS/split/'baseline_summary.json').read_text())
    for channel in CHANNELS:
        selected = [r for r in rows if r['review_channel']==channel]
        assert len(selected)==n
        accepted = [r for r in selected if r['status']=='ok']
        paired = [r for r in accepted if r['ecg_reference']['status']=='ok']
        assert summary[channel]['coverage']==len(accepted)/n
        if paired:
            errors = np.asarray([r['hr_bpm']-r['ecg_reference']['hr_bpm'] for r in paired])
            assert summary[channel]['mae_bpm']==pytest.approx(np.mean(abs(errors)))
            assert summary[channel]['rmse_bpm']==pytest.approx(np.sqrt(np.mean(errors**2)))
        else:
            assert summary[channel]['mae_bpm'] is None and summary[channel]['rmse_bpm'] is None
        assert all(r['spo2_percent'] is None for r in selected)


def test_no_revised_processor_or_retrospective_promotion_was_released():
    decision = json.loads((ARTIFACTS/'development_decision.json').read_text())
    assert decision['selected_candidate'] is None
    assert decision['revised_processor_release'] is False
    assert decision['retrospective_is_not_untouched_holdout'] is True
    for name,digest in decision['code_sha256'].items():
        assert hashlib.sha256((HERE/name).read_bytes()).hexdigest()==digest
    retrospective = json.loads((ARTIFACTS/'retrospective/interpretation.json').read_text())
    assert retrospective['development_decision_unchanged']==decision
    assert retrospective['no_retrospective_model_or_threshold_selection'] is True


def test_threshold_sensitivity_changes_one_gate_and_is_not_promotable():
    decision = json.loads((ARTIFACTS/'development_decision.json').read_text())
    baseline = decision['baseline_config']
    rows = json.loads((ARTIFACTS/'development/gate_sensitivity.json').read_text())
    assert len(rows)==18
    for row in rows:
        changed = [key for key in baseline if row['config'][key]!=baseline[key]]
        assert len(changed)==1
        assert row['diagnostic_only_not_promotable'] is True


def test_correct_median_error_does_not_override_irregular_or_weak_waveform_quality():
    rows = load_rows(ARTIFACTS/'retrospective/baseline_windows.jsonl')
    cases = [r for r in rows if r['review_channel']=='Green' and r['review_session']==5 and r['diagnostics']['ungated_abs_error_bpm']<3]
    assert len(cases)==4
    assert all(r['status']=='rejected' and r['hr_bpm'] is None for r in cases)
    assert all(r['diagnostics']['category']=='weak_or_distorted_waveform_evidence' for r in cases)
