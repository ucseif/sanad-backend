"""Analytic fixtures test mechanics; these signals are NOT WF-PPG benchmark data."""
import json
import numpy as np
import pytest
from sanad_ppg import Config, bandpass, ecg_reference, process_recording, ppg_hr


def analytic(seconds=30, fs=100):
    t = np.arange(seconds * fs) / fs
    p = 1000 + 20 * np.sin(2 * np.pi * 1.2 * t)
    return t, {'PPG_Wrist_G': p, 'PPG_Wrist_R': 2*p, 'PPG_Wrist_IR': 3*p}


def test_filter_removes_dc_drift_and_high_frequency_preserves_pulse():
    t = np.arange(6000)/100
    pulse = np.sin(2*np.pi*1.2*t)
    out = bandpass(50 + pulse + 2*np.sin(2*np.pi*.1*t) + np.sin(2*np.pi*20*t), 100)
    assert np.corrcoef(out[200:-200], pulse[200:-200])[0, 1] > .99
    assert abs(np.std(out[200:-200]) - np.std(pulse[200:-200])) < .03


@pytest.mark.parametrize('bpm', [45, 72, 120, 170])
def test_ppg_peak_detection_known_analytic_frequency(bpm):
    t = np.arange(3000)/100
    result = ppg_hr(np.sin(2*np.pi*bpm/60*t), 100, Config())
    assert result['status'] == 'ok'
    assert abs(result['hr_bpm'] - bpm) < 1


@pytest.mark.parametrize('bpm', [45, 72, 120, 170])
def test_ecg_energy_detector_against_known_qrs_locations(bpm):
    t = np.arange(3000)/100
    truth = np.arange(1, 29, 60/bpm)
    ecg = sum(np.exp(-.5*((t-r)/.015)**2) for r in truth) + .03*np.sin(2*np.pi*.3*t)
    result = ecg_reference(ecg, 100, Config())
    assert result['status'] == 'ok'
    assert abs(result['hr_bpm'] - bpm) < 1
    actual = np.asarray(result['r_peaks_s'])
    usable = truth[(truth >= 2) & (truth < 28)]
    assert all(np.min(abs(actual - r)) < .04 for r in usable)


def test_timestamps_window_contract_features_and_null_spo2():
    t, channels = analytic(65)
    rows = process_recording(t+360, channels, Config(), recording_id='analytic')
    assert len(rows) == 3
    assert rows[0]['window_start_s'] == 360
    assert rows[0]['window_end_s'] == 390
    assert rows[1]['window_start_s'] == 390
    assert rows[-1]['status'] == 'rejected'
    assert 'incomplete_window' in rows[-1]['reasons']
    assert rows[0]['spo2_percent'] is None
    assert abs(rows[0]['red_ir_features']['ratio_of_ratios'] - 1) < .01
    json.dumps(rows, allow_nan=False)


@pytest.mark.parametrize('bad', ['nan', 'flat', 'gap', 'rate'])
def test_bad_window_is_rejected_without_filling_missing_samples(bad):
    t, channels = analytic()
    if bad == 'nan': channels['PPG_Wrist_G'][100] = np.nan
    if bad == 'flat': channels['PPG_Wrist_G'][:] = 1
    if bad == 'gap': t[1500:] += 1
    if bad == 'rate': t *= 2
    rows = process_recording(t, channels, Config())
    assert rows[0]['status'] == 'rejected'
    assert rows[0]['hr_bpm'] is None


@pytest.mark.parametrize('mutation', ['duplicate','decrease','time_nan','length'])
def test_invalid_record_structure_fails_explicitly(mutation):
    t, channels = analytic()
    if mutation == 'duplicate': t[50] = t[49]
    if mutation == 'decrease': t[50] = t[49] - 1
    if mutation == 'time_nan': t[10] = np.nan
    if mutation == 'length': channels['PPG_Wrist_G'] = channels['PPG_Wrist_G'][:-1]
    with pytest.raises(ValueError): process_recording(t, channels, Config())


def test_noise_quality_rejected_and_dc_missing_features_do_not_create_spo2():
    rng = np.random.default_rng(20261109)
    result = ppg_hr(rng.normal(size=3000), 100, Config())
    assert result['status'] == 'rejected'
    t, channels = analytic()
    channels['PPG_Wrist_R'] = np.zeros(len(t))
    row = process_recording(t, channels, Config())[0]
    assert row['status'] == 'ok'
    assert row['red_ir_features']['status'] == 'unavailable'
    assert row['spo2_percent'] is None


def test_reproducible_jitter_handling_and_ecg_cannot_change_ppg_quality():
    t, channels = analytic()
    t[1:-1] += .002*np.sin(np.arange(1, len(t)-1))
    a = process_recording(t, channels, Config())[0]
    channels['ECG'] = np.zeros(len(t))
    b = process_recording(t, channels, Config())[0]
    assert a['status'] == b['status'] == 'ok'
    assert a['hr_bpm'] == b['hr_bpm']
    assert abs(a['hr_bpm'] - 72) < 1
    assert b['ecg_reference']['hr_bpm'] is None


def test_config_and_filter_validation():
    with pytest.raises(ValueError): Config(fs=20)
    with pytest.raises(ValueError): bandpass(np.ones(3), 100)
    with pytest.raises(ValueError): Config(window_s=2)


@pytest.mark.parametrize('helper', [ppg_hr, ecg_reference])
@pytest.mark.parametrize('values', [np.sin(np.arange(100)), np.ones((10, 10)), np.array([])])
def test_exported_helpers_reject_short_or_nonvector_inputs_explicitly(helper, values):
    with pytest.raises(ValueError): helper(values, 100, Config())
