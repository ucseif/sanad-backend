import numpy as np
import pytest
from candidates import candidate_hr, choose_candidate
from sanad_ppg import Config


@pytest.mark.parametrize('bpm',[45,72,120,170])
@pytest.mark.parametrize('fraction',[.55,.65,.75])
def test_conservative_candidates_preserve_known_analytic_hr(bpm,fraction):
    t = np.arange(3000)/100
    result = candidate_hr(np.sin(2*np.pi*bpm/60*t),100,Config(),fraction)
    assert result['status'] == 'ok'
    assert abs(result['hr_bpm']-bpm) < 1


def test_secondary_wave_peak_is_not_silently_counted_as_second_heartbeat():
    t = np.arange(3000)/100
    x = np.sin(2*np.pi*1.2*t)+.8*np.sin(2*np.pi*2.4*t)
    result = candidate_hr(x,100,Config(),.65)
    assert result['status'] == 'ok' and abs(result['hr_bpm']-72) < 1


def test_dominant_second_harmonic_is_rejected_as_ambiguous():
    t = np.arange(3000)/100
    result = candidate_hr(np.sin(2*np.pi*1.2*t)+2*np.sin(2*np.pi*2.4*t),100,Config(),.65)
    assert result['status'] == 'rejected'
    assert 'harmonic_ambiguity' in result['reasons']


def test_noise_and_invalid_inputs_do_not_create_estimates():
    rng = np.random.default_rng(20261110)
    assert candidate_hr(rng.normal(size=3000),100,Config(),.65)['status'] == 'rejected'
    assert candidate_hr(np.zeros(3000),100,Config(),.65)['status'] == 'rejected'
    assert candidate_hr(np.full(3000,np.nan),100,Config(),.65)['status'] == 'rejected'
    with pytest.raises(ValueError): candidate_hr(np.ones(10),100,Config(),.65)


def test_channel_failures_cannot_be_hidden_by_aggregate_development_improvement():
    rows = [
        {'candidate':'guided_0.65','channel':'Red','accepted':3,'paired':3,'lost_previous':0,'new_errors_gt3':0,'max_abs_error_bpm':1.,'baseline_max_abs_error_bpm':2.},
        {'candidate':'guided_0.65','channel':'IR','accepted':1,'paired':1,'lost_previous':0,'new_errors_gt3':1,'max_abs_error_bpm':10.,'baseline_max_abs_error_bpm':2.},
    ]
    assert choose_candidate(rows,{'Red':2,'IR':0}) is None


def test_unreferenced_new_acceptance_cannot_count_as_reliability_improvement():
    rows = [
        {'candidate':'guided_0.65','channel':'Red','accepted':1,'paired':0,'lost_previous':0,'new_errors_gt3':0,'max_abs_error_bpm':None,'baseline_max_abs_error_bpm':None},
        {'candidate':'guided_0.65','channel':'IR','accepted':0,'paired':0,'lost_previous':0,'new_errors_gt3':0,'max_abs_error_bpm':None,'baseline_max_abs_error_bpm':None},
    ]
    assert choose_candidate(rows,{'Red':0,'IR':0}) is None


def test_fully_referenced_reliable_gain_is_not_rejected_unconditionally():
    rows = [
        {'candidate':'guided_0.65','channel':c,'accepted':1,'paired':1,'lost_previous':0,'new_errors_gt3':0,'max_abs_error_bpm':1.,'baseline_max_abs_error_bpm':None}
        for c in ['Red','IR']
    ]
    assert choose_candidate(rows,{'Red':0,'IR':0})=='guided_0.65'
