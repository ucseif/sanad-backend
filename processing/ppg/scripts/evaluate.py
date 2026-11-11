"""Deterministic evaluation on real files; no inference/training or provider calls."""
import argparse
from collections import Counter
from dataclasses import asdict
import hashlib
import json
from pathlib import Path
import platform
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'src'))
import numpy as np
import scipy
from sanad_ppg import Config, process_recording
from sanad_ppg.dataset import load_recording, validate_indices


def dump(path, value):
    path.write_text(json.dumps(value, indent=2, allow_nan=False), encoding='utf8')


def metrics(rows):
    paired = [r for r in rows if r['status'] == 'ok' and r['ecg_reference']['status'] == 'ok']
    errors = np.asarray([r['hr_bpm']-r['ecg_reference']['hr_bpm'] for r in paired])
    valid_ref = sum(r['ecg_reference']['status'] == 'ok' for r in rows)
    accepted = sum(r['status'] == 'ok' for r in rows)
    return {'total_windows': len(rows), 'ppg_accepted': accepted, 'ppg_rejected': len(rows)-accepted,
            'ppg_coverage': accepted/len(rows) if rows else None, 'valid_ecg_windows': valid_ref,
            'invalid_ecg_windows': len(rows)-valid_ref, 'paired_windows': len(paired),
            'paired_coverage_all': len(paired)/len(rows) if rows else None,
            'paired_coverage_valid_ecg': len(paired)/valid_ref if valid_ref else None,
            'mae_bpm': float(np.mean(abs(errors))) if len(errors) else None,
            'rmse_bpm': float(np.sqrt(np.mean(errors**2))) if len(errors) else None,
            'bias_bpm': float(np.mean(errors)) if len(errors) else None,
            'ppg_rejection_reasons': dict(Counter(v for r in rows for v in r['reasons'])),
            'ecg_rejection_reasons': dict(Counter(v for r in rows for v in r['ecg_reference']['reasons']))}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('dataset', type=Path)
    parser.add_argument('--split', choices=['development', 'evaluation'], required=True)
    parser.add_argument('--reports', type=Path, default=ROOT/'reports')
    args = parser.parse_args()
    # Check all twelve files before reading or evaluating a partial dataset.
    for s in range(6):
        for kind in ['preprocessed', 'indices']:
            path = args.dataset/f'{s}_{kind}_03.csv'
            if not path.is_file(): raise FileNotFoundError(path)
    cfg = Config()
    code_files = sorted((ROOT/'src'/'sanad_ppg').glob('*.py'))
    code_hash = hashlib.sha256(b''.join(p.read_bytes() for p in code_files)).hexdigest()
    frozen = {'config': asdict(cfg), 'code_sha256': code_hash, 'development_sessions': [0, 1], 'evaluation_sessions': [2, 3, 4, 5]}
    args.reports.mkdir(parents=True, exist_ok=True)
    freeze_path = args.reports/'frozen_baseline.json'
    if args.split == 'evaluation' and (not freeze_path.is_file() or json.loads(freeze_path.read_text()) != frozen):
        raise ValueError('Run development first and freeze identical config/code before evaluation.')
    if args.split == 'development':
        if freeze_path.exists() and json.loads(freeze_path.read_text()) != frozen:
            raise ValueError('Configuration/code changed: use a new reports directory and document the revision.')
        dump(freeze_path, frozen)
    sessions = frozen[args.split+'_sessions']
    destination = args.reports/args.split
    destination.mkdir(parents=True, exist_ok=True)
    all_rows = []
    by_session = {}
    for s in sessions:
        t, channels = load_recording(args.dataset, s)
        indices = validate_indices(args.dataset, s, len(t))
        rows = process_recording(t, channels, cfg, recording_id=f'WF-PPG/03/{s}')
        # Retain original SpO2 only as measured-reference metadata, never predicted SpO2.
        for row in rows:
            mask = (t >= row['window_start_s']) & (t < row['window_end_s'])
            spo2 = channels['SpO2'][mask]
            row['dataset_reference_spo2_percent_median'] = float(np.median(spo2)) if len(spo2) and np.isfinite(spo2).all() else None
        by_session[str(s)] = {**metrics(rows), 'indices_rows': len(indices),
                              'source_sha256': hashlib.sha256((args.dataset/f'{s}_preprocessed_03.csv').read_bytes()).hexdigest()}
        all_rows.extend(rows)
    (destination/'windows.jsonl').write_text(''.join(json.dumps(r, allow_nan=False)+'\n' for r in all_rows), encoding='utf8')
    dump(destination/'summary.json', {'split': args.split, 'subject': '03', 'config': frozen,
        'python': platform.python_version(), 'numpy': np.__version__, 'scipy': scipy.__version__,
        'aggregate': metrics(all_rows), 'sessions': by_session,
        'reference': 'ECG-derived QRS energy, cross-checked against independent absolute-amplitude ECG peaks; no annotated ECG ground truth supplied'})
    dump(destination/'sample_output.json', all_rows[0])
    print(json.dumps({'split': args.split, **metrics(all_rows)}, indent=2, allow_nan=False))


if __name__ == '__main__': main()
