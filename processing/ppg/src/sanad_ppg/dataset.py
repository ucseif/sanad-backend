"""WF-PPG adapter only; dataset annotations never enter the shared HR algorithm."""
import csv
from pathlib import Path
import numpy as np


def load_recording(folder, session):
    path = Path(folder) / f'{session}_preprocessed_03.csv'
    with path.open(newline='') as handle:
        reader = csv.DictReader(handle)
        required = {'Time', 'PPG_Wrist_G', 'PPG_Wrist_R', 'PPG_Wrist_IR', 'ECG', 'SpO2', 'PPG_Wrist_G_AC'}
        if not required.issubset(reader.fieldnames or []):
            raise ValueError(f'Missing required columns in {path.name}')
        rows = list(reader)
    values = {k: np.asarray([float(r[k]) if r[k] else np.nan for r in rows]) for k in required}
    return values.pop('Time'), values


def validate_indices(folder, session, row_count):
    """Pulse-boundary row coordinates, not UTC times, ECG peaks or dense quality labels.

    Start/end inclusivity is not specified by CSV metadata; no endpoint convention
    or unannotated-region quality is inferred for processing/evaluation.
    """
    path = Path(folder) / f'{session}_indices_03.csv'
    with path.open(newline='') as handle:
        reader = csv.DictReader(handle)
        required = {'finger_s', 'finger_e', 'wrist_s', 'wrist_e', 'wrist_t', 'finger_t'}
        if not required.issubset(reader.fieldnames or []):
            raise ValueError(f'Missing indices fields in {path.name}')
        rows = list(reader)
    for row in rows:
        for prefix in ['finger', 'wrist']:
            if not 0 <= int(row[prefix+'_s']) < int(row[prefix+'_e']) < row_count:
                raise ValueError(f'Out-of-bounds waveform indices in {path.name}')
    return rows
