"""Research-only variants, not a revised processor release or streaming API.

Reuse frozen filtering, input validation, RR calculation, timing refinement and
baseline spectral features. No ECG, reference SpO2, other channel or labels enter.
"""
import numpy as np
from scipy import signal
from sanad_ppg import bandpass, ppg_hr
from sanad_ppg.processor import _fractional_peaks, _rr, _validate_helper

FRACTIONS = (.55,.65,.75)


def candidate_hr(values,fs,config,fraction):
    if fraction not in FRACTIONS: raise ValueError('Unsupported predeclared candidate.')
    x = _validate_helper(values,fs,config)
    baseline = ppg_hr(x,fs,config)
    if 'spectral_hr_bpm' not in baseline['quality']:
        return baseline
    edge = int(round(config.edge_s*fs))
    y = bandpass(x,fs)[edge:-edge]
    spectral_hr = baseline['quality']['spectral_hr_bpm']
    distance = max(int(fs*60/config.max_bpm),int(fraction*fs*60/spectral_hr))
    peaks,_ = signal.find_peaks(y,distance=distance,prominence=.5*(np.percentile(y,75)-np.percentile(y,25)))
    hr,cv = _rr(_fractional_peaks(y,peaks),fs,config)
    quality = {**baseline['quality'],'peak_count':len(peaks),'rr_cv':cv,
               'guided_minimum_separation_s':distance/fs,'candidate_fraction':fraction}
    if hr is None:
        return {'status':'rejected','hr_bpm':None,'reasons':['insufficient_or_implausible_peaks'],'quality':quality}
    centered = y-np.mean(y)
    corr = signal.correlate(centered,centered,mode='full',method='fft')[len(y)-1:]
    def acf(rate): return float(corr[int(round(60/rate*fs))]/max(corr[0],1e-30))
    periodicity = acf(hr)
    expected = (len(y)/fs)*hr/60
    quality.update({'autocorrelation':periodicity,'peak_spectrum_difference_bpm':abs(hr-spectral_hr),
                    'expected_beats_from_estimate':expected,'peak_count_difference':abs(len(peaks)-expected)})
    reasons = []
    if cv > config.max_rr_cv: reasons.append('irregular_pulse_intervals')
    if periodicity < config.min_autocorrelation: reasons.append('low_periodicity')
    if quality['spectral_concentration'] < config.min_spectral_concentration: reasons.append('diffuse_spectrum')
    if abs(hr-spectral_hr) > config.max_spectral_difference_bpm: reasons.append('peak_spectrum_disagreement')
    if abs(len(peaks)-expected) > .15*expected+1: reasons.append('peak_count_inconsistency')
    frequencies,power = signal.periodogram(y,fs=fs,window='hann',detrend='constant')
    main_power = power[abs(frequencies-spectral_hr/60)<=.2].sum()
    half_power = power[abs(frequencies-spectral_hr/120)<=.2].sum()
    ambiguity = spectral_hr/2 >= config.min_bpm and half_power/max(main_power,1e-30) >= .20 and acf(spectral_hr/2) > acf(spectral_hr)+.10
    quality['harmonic_ambiguity'] = bool(ambiguity)
    if ambiguity: reasons.append('harmonic_ambiguity')
    return {'status':'rejected' if reasons else 'ok','hr_bpm':None if reasons else hr,'reasons':reasons,'quality':quality}


def choose_candidate(comparisons,baseline_counts):
    eligible = []
    for name in sorted({r['candidate'] for r in comparisons}):
        selected = {r['channel']:r for r in comparisons if r['candidate']==name}
        if not all(c in selected for c in ['Red','IR']): continue
        ok = True
        for channel in ['Red','IR']:
            row = selected[channel]
            if row['accepted'] != row.get('paired',0):
                ok = False  # Missing ECG evidence is not zero error or a safe gain.
            limit = row['baseline_max_abs_error_bpm'] if row['baseline_max_abs_error_bpm'] is not None else 3.
            if row['accepted'] < baseline_counts[channel] or row['lost_previous'] or row['new_errors_gt3']:
                ok = False
            if row['max_abs_error_bpm'] is not None and row['max_abs_error_bpm'] > limit:
                ok = False
        gain = sum(selected[c]['accepted']-baseline_counts[c] for c in ['Red','IR'])
        if ok and gain > 0:
            eligible.append((-gain,name))
    return min(eligible)[1] if eligible else None
