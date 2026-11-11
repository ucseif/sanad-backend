from dataclasses import asdict, dataclass
import math
import numpy as np
from scipy import signal

VERSION = 'sanad-ppg/0.1.1'


@dataclass(frozen=True)
class Config:
    fs: float = 100.0
    window_s: float = 30.0
    edge_s: float = 2.0
    min_bpm: float = 40.0
    max_bpm: float = 180.0
    max_rr_cv: float = .20
    min_autocorrelation: float = .40
    min_spectral_concentration: float = .45
    max_spectral_difference_bpm: float = 12.0

    def __post_init__(self):
        if not all(math.isfinite(v) for v in asdict(self).values()):
            raise ValueError('Configuration must be finite.')
        if not 64 <= self.fs <= 1000 or self.window_s < 15 or not 1 <= self.edge_s < self.window_s/4:
            raise ValueError('Require fs 64..1000 Hz, windows >=15 s and valid edge exclusion.')
        if not 30 <= self.min_bpm < self.max_bpm <= 200:
            raise ValueError('Invalid HR range.')
        if not 0 < self.max_rr_cv < 1 or not 0 <= self.min_autocorrelation <= 1 or not 0 <= self.min_spectral_concentration <= 1 or self.max_spectral_difference_bpm <= 0:
            raise ValueError('Invalid quality thresholds.')


def bandpass(values, fs, low=.5, high=4., order=4):
    x = np.asarray(values, dtype=float)
    if x.ndim != 1 or len(x) < max(64, fs) or not np.isfinite(x).all():
        raise ValueError('Filter requires a finite one-dimensional signal of at least one second.')
    if not math.isfinite(fs) or not 0 < low < high < fs/2:
        raise ValueError('Invalid band edges or sampling frequency.')
    return signal.sosfiltfilt(signal.butter(order, [low, high], btype='bandpass', fs=fs, output='sos'), x)


def _rejected(reason, quality=None):
    return {'status': 'rejected', 'hr_bpm': None, 'reasons': [reason], 'quality': quality or {}}


def _rr(peaks, fs, config):
    intervals = np.diff(peaks)/fs
    if len(intervals) < 7:
        return None, None
    hr = float(60/np.median(intervals))
    cv = float(np.std(intervals)/np.mean(intervals))
    if not config.min_bpm <= hr <= config.max_bpm:
        return None, cv
    return hr, cv


def _fractional_peaks(values, peaks):
    """Three-point parabolic timing refinement; removes integer-sample RR bias."""
    output = []
    for peak in peaks:
        p = int(peak)
        delta = 0.
        if 0 < p < len(values)-1:
            denominator = values[p-1]-2*values[p]+values[p+1]
            if abs(denominator) > 1e-20:
                delta = float(np.clip(.5*(values[p-1]-values[p+1])/denominator, -.5, .5))
        output.append(p+delta)
    return np.asarray(output)


def _validate_helper(values, fs, config):
    x = np.asarray(values, dtype=float)
    if not math.isfinite(fs) or fs <= 0 or x.ndim != 1 or len(x) < max(64, math.ceil((2*config.edge_s+1)*fs)):
        raise ValueError('Require a one-dimensional signal with at least one second after edge exclusion and a finite positive rate.')
    return x


def ppg_hr(values, fs, config):
    x = _validate_helper(values, fs, config)
    if not np.isfinite(x).all():
        return _rejected('nonfinite_ppg')
    if np.ptp(x) <= 1e-9*max(1., float(np.max(np.abs(x)))):
        return _rejected('flat_ppg')
    y = bandpass(x, fs)
    edge = int(round(config.edge_s*fs))
    y = y[edge:-edge]
    iqr = float(np.percentile(y, 75)-np.percentile(y, 25))
    if iqr <= 1e-12:
        return _rejected('flat_filtered_ppg')
    peaks, _ = signal.find_peaks(y, distance=max(1, int(fs*60/config.max_bpm)), prominence=.5*iqr)
    hr, cv = _rr(_fractional_peaks(y, peaks), fs, config)
    quality = {'peak_count': len(peaks), 'rr_cv': cv}
    if hr is None:
        return _rejected('insufficient_or_implausible_peaks', quality)
    frequencies, power = signal.periodogram(y, fs=fs, window='hann', detrend='constant')
    cardiac = (frequencies >= config.min_bpm/60) & (frequencies <= config.max_bpm/60)
    f = float(frequencies[cardiac][np.argmax(power[cardiac])])
    concentration = float(power[cardiac & (np.abs(frequencies-f) <= .2)].sum()/max(power[cardiac].sum(), 1e-30))
    centered = y-np.mean(y)
    correlation = signal.correlate(centered, centered, mode='full', method='fft')[len(y)-1:]
    lag = int(round(60/hr*fs))
    autocorrelation = float(correlation[lag]/max(correlation[0], 1e-30))
    quality.update({'autocorrelation': autocorrelation, 'spectral_concentration': concentration,
                    'spectral_hr_bpm': f*60, 'peak_spectrum_difference_bpm': abs(hr-f*60)})
    reasons = []
    if cv > config.max_rr_cv: reasons.append('irregular_pulse_intervals')
    if autocorrelation < config.min_autocorrelation: reasons.append('low_periodicity')
    if concentration < config.min_spectral_concentration: reasons.append('diffuse_spectrum')
    if abs(hr-f*60) > config.max_spectral_difference_bpm: reasons.append('peak_spectrum_disagreement')
    return {'status': 'rejected' if reasons else 'ok', 'hr_bpm': None if reasons else hr,
            'reasons': reasons, 'quality': quality}


def _match_fraction(a, b, tolerance):
    """One-to-one chronological matching; neither detector can match a beat twice."""
    i = j = matched = 0
    while i < len(a) and j < len(b):
        if abs(a[i]-b[j]) <= tolerance:
            matched += 1; i += 1; j += 1
        elif a[i] < b[j]: i += 1
        else: j += 1
    return 2*matched/max(1, len(a)+len(b))


def ecg_reference(values, fs, config):
    """Independent ECG reference: QRS energy plus amplitude-detector cross-check.

    Cross-checked derived reference, NOT annotated R-peak ground truth.
    """
    x = _validate_helper(values, fs, config)
    if not np.isfinite(x).all() or np.ptp(x) < 1e-9:
        return _rejected('invalid_ecg')
    y = bandpass(x, fs, 8., 25., 3)
    energy = np.convolve(np.gradient(y)**2, np.ones(int(.12*fs))/int(.12*fs), mode='same')
    baseline = np.median(energy)
    threshold = max(baseline+3*np.median(abs(energy-baseline)), .15*np.percentile(energy, 99))
    candidates, _ = signal.find_peaks(energy, distance=int(.28*fs), height=threshold, prominence=threshold*.5)
    radius = max(1, int(.08*fs))
    refined = sorted(set(max(0, int(p)-radius)+int(np.argmax(abs(y[max(0, int(p)-radius):min(len(y), int(p)+radius+1)]))) for p in candidates))
    edge = int(round(config.edge_s*fs))
    primary = _fractional_peaks(abs(y), [p for p in refined if edge <= p < len(x)-edge])
    secondary, _ = signal.find_peaks(abs(x-np.median(x)), distance=int(.35*fs), prominence=.3)
    secondary = secondary[(secondary >= edge) & (secondary < len(x)-edge)]
    secondary = _fractional_peaks(abs(x-np.median(x)), secondary)
    hr, cv = _rr(primary, fs, config)
    alternate_hr, _ = _rr(secondary, fs, config)
    agreement = _match_fraction(primary/fs, secondary/fs, .08)
    quality = {'primary_peak_count': len(primary), 'alternate_peak_count': len(secondary),
               'event_f1_vs_alternate': agreement, 'alternate_hr_bpm': alternate_hr, 'rr_cv': cv}
    reasons = []
    if hr is None or cv is None or cv > config.max_rr_cv: reasons.append('invalid_ecg_intervals')
    if alternate_hr is None or hr is None or abs(hr-alternate_hr) > 5 or agreement < .9:
        reasons.append('ecg_detector_disagreement')
    return {'status': 'rejected' if reasons else 'ok', 'hr_bpm': None if reasons else hr,
            'reasons': reasons, 'quality': quality, 'r_peaks_s': (primary/fs).tolist()}


def _red_ir(channels, fs, config):
    features = {'status': 'unavailable', 'reasons': [], 'red_dc': None, 'ir_dc': None,
                'red_ac_rms': None, 'ir_ac_rms': None, 'red_ac_dc': None, 'ir_ac_dc': None,
                'ratio_of_ratios': None, 'calibrated': False}
    edge = int(round(config.edge_s*fs))
    for color, name in [('red', 'PPG_Wrist_R'), ('ir', 'PPG_Wrist_IR')]:
        if name not in channels or not np.isfinite(channels[name]).all():
            features['reasons'].append('missing_or_nonfinite_'+color); continue
        x = channels[name]
        dc = float(np.median(x[edge:-edge]))
        ac = float(np.sqrt(np.mean(bandpass(x, fs)[edge:-edge]**2)))
        features[color+'_dc'] = dc
        features[color+'_ac_rms'] = ac
        if dc <= 1e-9 or ac <= 1e-12:
            features['reasons'].append('invalid_ac_dc_'+color)
        else: features[color+'_ac_dc'] = ac/dc
    if not features['reasons']:
        features['ratio_of_ratios'] = features['red_ac_dc']/features['ir_ac_dc']
        features['status'] = 'exploratory'
    return features


def process_recording(time_s, channels, config=None, recording_id='unspecified'):
    """Process aligned arrays into versioned JSON-compatible window records.

    Structural errors raise ValueError. Invalid windows produce explicit rejections.
    Input time is seconds relative to acquisition, never an invented UTC timestamp.
    """
    config = config or Config()
    t = np.asarray(time_s, dtype=float)
    if t.ndim != 1 or len(t) < 2 or not np.isfinite(t).all() or np.any(np.diff(t) <= 0):
        raise ValueError('Time must be finite, strictly increasing seconds; no sorting or deduplication is performed.')
    arrays = {k: np.asarray(v, dtype=float) for k, v in channels.items()}
    if 'PPG_Wrist_G' not in arrays or any(v.ndim != 1 or len(v) != len(t) for v in arrays.values()):
        raise ValueError('Require aligned same-length channel arrays and PPG_Wrist_G.')
    duration = t[-1]-t[0]+1/config.fs
    complete = int(math.floor((duration+3/config.fs)/config.window_s))
    count = complete + int(duration > complete*config.window_s+3/config.fs)
    output = []
    for index in range(max(1, count)):
        start = float(t[0]+index*config.window_s)
        end = float(start+config.window_s)
        row = {'schema_version': '1.0', 'processor_version': VERSION, 'recording_id': str(recording_id),
               'window_index': index, 'window_start_s': start, 'window_end_s': end,
               'time_basis': 'recording_seconds', 'analysis_start_s': start+config.edge_s,
               'analysis_end_s': end-config.edge_s, 'processing_fs_hz': config.fs,
               'status': 'rejected', 'reasons': [], 'hr_bpm': None, 'quality': {},
               'ecg_reference': _rejected('ecg_unavailable'), 'red_ir_features': {'status': 'unavailable', 'reasons': ['unprocessed_window'], 'calibrated': False},
               'spo2_percent': None}
        if end > t[-1]+4/config.fs:
            row['reasons'] = ['incomplete_window']; output.append(row); continue
        left = max(0, np.searchsorted(t, start, side='right')-1)
        right = min(len(t), np.searchsorted(t, end, side='left')+1)
        tw = t[left:right]
        effective_fs = (len(tw)-1)/(tw[-1]-tw[0])
        row['quality'] = {'input_effective_fs_hz': float(effective_fs),
                          'max_input_gap_s': float(np.max(np.diff(tw))),
                          'input_samples': len(tw), 'resampled': True}
        if abs(effective_fs/config.fs-1) > .1: row['reasons'].append('sampling_rate_mismatch')
        if np.max(np.diff(tw)) > 3/config.fs: row['reasons'].append('time_gap')
        if row['reasons']: output.append(row); continue
        grid = start+np.arange(round(config.window_s*config.fs))/config.fs
        # At most three samples of boundary hold are allowed; never bridge long gaps.
        uniform = {k: np.interp(grid, tw, v[left:right]) if np.isfinite(v[left:right]).all()
                   else np.full(len(grid), np.nan) for k, v in arrays.items()}
        if not np.isfinite(arrays['PPG_Wrist_G'][left:right]).all():
            pulse = _rejected('nonfinite_ppg')
        else: pulse = ppg_hr(uniform['PPG_Wrist_G'], config.fs, config)
        row['status'], row['hr_bpm'], row['reasons'] = pulse['status'], pulse['hr_bpm'], pulse['reasons']
        row['quality'].update(pulse['quality'])
        if 'ECG' in uniform: row['ecg_reference'] = ecg_reference(uniform['ECG'], config.fs, config)
        row['red_ir_features'] = _red_ir(uniform, config.fs, config)
        output.append(row)
    return output
