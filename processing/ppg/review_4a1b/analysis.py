"""Read-only research diagnostics; never changes baseline algorithms or artifacts."""
from collections import Counter
from pathlib import Path
import hashlib
import json
import numpy as np
from scipy import signal
from sanad_ppg import Config, bandpass, process_recording
from sanad_ppg.processor import _fractional_peaks

CHANNELS = {'Green':'PPG_Wrist_G', 'Red':'PPG_Wrist_R', 'IR':'PPG_Wrist_IR'}


def channel_view(channels, channel):
    if channel not in CHANNELS or CHANNELS[channel] not in channels:
        raise ValueError('Requested real channel is unavailable; no green substitution is allowed.')
    # Research adapter for the frozen green-named entrypoint. The report always
    # records the true source channel; MAX30102 does not magically supply green.
    return {**channels, 'PPG_Wrist_G': channels[CHANNELS[channel]]}


def preservation_snapshot(root, paths):
    root = Path(root)
    return {str(Path(p).relative_to(root)).replace('\\','/'): hashlib.sha256(Path(p).read_bytes()).hexdigest() for p in sorted(paths)}


def verify_preserved(root, snapshot):
    changed = [name for name, digest in snapshot.items() if not (Path(root)/name).is_file() or hashlib.sha256((Path(root)/name).read_bytes()).hexdigest() != digest]
    if changed: raise RuntimeError('Preserved files changed: '+', '.join(changed))
    return len(snapshot)


def summarize(rows):
    accepted = [r for r in rows if r['status'] == 'ok']
    paired = [r for r in accepted if r['ecg_reference']['status'] == 'ok']
    errors = np.asarray([r['hr_bpm']-r['ecg_reference']['hr_bpm'] for r in paired])
    valid_ref = sum(r['ecg_reference']['status'] == 'ok' for r in rows)
    return {'windows':len(rows), 'accepted':len(accepted), 'rejected':len(rows)-len(accepted),
            'coverage':len(accepted)/len(rows) if rows else None, 'paired':len(paired),
            'invalid_ecg':len(rows)-valid_ref, 'paired_coverage':len(paired)/len(rows) if rows else None,
            'mae_bpm':float(np.mean(abs(errors))) if len(errors) else None,
            'rmse_bpm':float(np.sqrt(np.mean(errors**2))) if len(errors) else None,
            'max_abs_error_bpm':float(max(abs(errors))) if len(errors) else None,
            'accepted_errors_gt3_bpm':int(np.sum(abs(errors)>3)),
            'reasons':dict(Counter(v for r in rows for v in r['reasons']))}


def uniform_window(t, values, row, config=None):
    config = config or Config()
    left = max(0, np.searchsorted(t, row['window_start_s'], side='right')-1)
    right = min(len(t), np.searchsorted(t, row['window_end_s'], side='left')+1)
    grid = row['window_start_s']+np.arange(round(config.window_s*config.fs))/config.fs
    return np.interp(grid, t[left:right], values[left:right])


def diagnose(values, row, config=None):
    """ECG-assisted interpretation is explicitly post hoc, never a quality gate."""
    cfg = config or Config()
    edge = int(round(cfg.edge_s*cfg.fs))
    y = bandpass(values, cfg.fs)[edge:-edge]
    iqr = np.percentile(y,75)-np.percentile(y,25)
    integer_peaks, _ = signal.find_peaks(y, distance=int(cfg.fs*60/cfg.max_bpm), prominence=.5*iqr)
    peaks = _fractional_peaks(y, integer_peaks)
    rr = np.diff(peaks)/cfg.fs
    hr = float(60/np.median(rr)) if len(rr) else None
    frequencies, power = signal.periodogram(y,fs=cfg.fs,window='hann',detrend='constant')
    cardiac = (frequencies >= cfg.min_bpm/60) & (frequencies <= cfg.max_bpm/60)
    f = float(frequencies[cardiac][np.argmax(power[cardiac])])
    concentration = float(power[cardiac & (abs(frequencies-f)<=.2)].sum()/max(power[cardiac].sum(),1e-30))
    centered = y-np.mean(y)
    corr = signal.correlate(centered,centered,mode='full',method='fft')[len(y)-1:]
    def acf(rate):
        if rate is None or rate <= 0: return None
        lag = int(round(cfg.fs*60/rate))
        return float(corr[lag]/max(corr[0],1e-30)) if lag < len(corr) else None
    ref = row['ecg_reference']['hr_bpm'] if row['ecg_reference']['status'] == 'ok' else None
    error = abs(hr-ref) if hr is not None and ref is not None else None
    ref_acf = acf(ref)
    cv = float(np.std(rr)/np.mean(rr)) if len(rr) else None
    if row['status'] == 'ok': category = 'accepted'
    elif ref is None: category = 'reference_unavailable'
    elif error is not None and error > 3 and abs(f*60-ref) <= 3 and ref_acf >= cfg.min_autocorrelation:
        category = 'peak_detection_failure_evidence'
    elif error is not None and error <= 3 and len(row['reasons']) == 1 and cv is not None and cv <= .25 and acf(hr) >= .30 and concentration >= .35:
        category = 'potential_threshold_over_rejection'
    elif ref_acf < cfg.min_autocorrelation and (concentration < cfg.min_spectral_concentration or (cv is not None and cv > cfg.max_rr_cv)):
        category = 'weak_or_distorted_waveform_evidence'
    else: category = 'mixed_or_unresolved'
    return {'category':category, 'ungated_peak_hr_bpm':hr, 'ungated_abs_error_bpm':error,
            'spectral_hr_bpm':f*60, 'spectral_abs_error_bpm':abs(f*60-ref) if ref else None,
            'acf_at_detected_period':acf(hr), 'acf_at_ecg_period_diagnostic_only':ref_acf,
            'spectral_concentration':concentration, 'peak_count':len(peaks), 'rr_cv':cv,
            'rr_min_s':float(min(rr)) if len(rr) else None, 'rr_max_s':float(max(rr)) if len(rr) else None,
            'expected_ecg_beats_diagnostic_only':(cfg.window_s-2*cfg.edge_s)*ref/60 if ref else None,
            'integer_peak_offsets_s':(integer_peaks/cfg.fs+cfg.edge_s).tolist(),
            'ecg_assisted_category_not_ground_truth':True}


def dump(path, value):
    Path(path).write_text(json.dumps(value,indent=2,allow_nan=False),encoding='utf8')
