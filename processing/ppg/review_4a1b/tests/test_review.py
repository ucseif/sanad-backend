"""Analytic fixtures test review mechanics, never substitute for real benchmarks."""
import numpy as np
import pytest
from analysis import channel_view, preservation_snapshot, verify_preserved, summarize, diagnose
from sanad_ppg import Config, process_recording


def test_channel_mapping_is_explicit_and_does_not_mutate_input():
    green, red = np.ones(3000), np.arange(3000.)
    original = {'PPG_Wrist_G': green, 'PPG_Wrist_R': red}
    mapped = channel_view(original, 'Red')
    assert mapped['PPG_Wrist_G'] is red
    assert original['PPG_Wrist_G'] is green
    with pytest.raises(ValueError): channel_view(original, 'IR')


def test_original_artifact_mutation_is_detected(tmp_path):
    file = tmp_path/'original.json'
    file.write_text('{}')
    snapshot = preservation_snapshot(tmp_path, [file])
    verify_preserved(tmp_path, snapshot)
    file.write_text('{"changed":true}')
    with pytest.raises(RuntimeError): verify_preserved(tmp_path, snapshot)


def test_undefined_error_is_null_and_coverage_denominator_includes_rejections():
    rows = [{'status':'rejected','hr_bpm':None,'reasons':['poor'], 'ecg_reference': {'status':'ok','hr_bpm':70.}}]
    result = summarize(rows)
    assert result['mae_bpm'] is None and result['accepted'] == 0 and result['coverage'] == 0
    rows.append({'status':'ok','hr_bpm':72.,'reasons':[], 'ecg_reference': {'status':'ok','hr_bpm':70.}})
    result = summarize(rows)
    assert result['coverage'] == .5 and result['mae_bpm'] == 2


def test_ecg_assisted_diagnostics_never_change_processing_acceptance():
    t = np.arange(3000)/100
    x = np.sin(2*np.pi*1.2*t)
    row = process_recording(t, {'PPG_Wrist_G': x}, Config())[0]
    a = diagnose(x, row)
    changed = dict(row, ecg_reference={'status':'ok','hr_bpm':100.})
    b = diagnose(x, changed)
    assert a['ungated_peak_hr_bpm'] == b['ungated_peak_hr_bpm']
    assert a['spectral_hr_bpm'] == b['spectral_hr_bpm']
    assert row['status'] == 'ok'
