"""Read-only inventory of the exact supplied files; no signal algorithm fitting."""
import argparse
import csv
import hashlib
import json
import math
from collections import Counter
from pathlib import Path
from statistics import median


def inspect(folder):
    records = []
    for session in range(6):
        path = folder / f'{session}_preprocessed_03.csv'
        index_path = folder / f'{session}_indices_03.csv'
        if not path.is_file() or not index_path.is_file():
            raise FileNotFoundError(f'Missing dataset pair for session {session}')
        with path.open(newline='') as handle:
            reader = csv.DictReader(handle)
            columns = reader.fieldnames
            rows = list(reader)
        arrays = {key: [float(r[key]) if r[key] else float('nan') for r in rows] for key in columns}
        time = arrays['Time']
        dt = [b-a for a, b in zip(time, time[1:])]
        positive = sorted(d for d in dt if d > 0)
        with index_path.open(newline='') as handle:
            indices = list(csv.DictReader(handle))
        records.append({
            'session': session, 'file': path.name, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
            'indices_sha256': hashlib.sha256(index_path.read_bytes()).hexdigest(),
            'rows': len(rows), 'columns': columns, 'start_s': time[0], 'end_s': time[-1],
            'duration_s': time[-1]-time[0], 'effective_hz': (len(time)-1)/(time[-1]-time[0]),
            'median_dt_s': median(dt), 'min_dt_s': min(dt), 'max_dt_s': max(dt),
            'dt_p01_s': positive[int(.01*(len(positive)-1))], 'dt_p99_s': positive[int(.99*(len(positive)-1))],
            'non_increasing_time': sum(d <= 0 for d in dt),
            'missing_nonfinite': {k: sum(not math.isfinite(v) for v in a) for k, a in arrays.items()},
            'ranges': {k: [min(v for v in a if math.isfinite(v)), max(v for v in a if math.isfinite(v))] for k, a in arrays.items()},
            'indices_rows': len(indices),
            'index_bounds': {k: [min(int(r[k]) for r in indices), max(int(r[k]) for r in indices)] for k in ['finger_s','finger_e','wrist_s','wrist_e']},
            'indices_in_bounds': all(0 <= int(r[k]) < len(rows) for r in indices for k in ['finger_s','finger_e','wrist_s','wrist_e']),
            'indices_ordered': all(int(r['wrist_s']) < int(r['wrist_e']) and int(r['finger_s']) < int(r['finger_e']) for r in indices),
            'wrist_classes': dict(Counter(r['wrist_t'] for r in indices)),
            'finger_classes': dict(Counter(r['finger_t'] for r in indices)),
        })
    return {'recordings': records, 'file_count': 12}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('dataset', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = inspect(args.dataset)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2, allow_nan=False), encoding='utf8')
    for r in result['recordings']:
        print({k: r[k] for k in ['session','rows','duration_s','effective_hz','median_dt_s','min_dt_s','max_dt_s','non_increasing_time','indices_in_bounds']})
