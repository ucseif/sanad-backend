"""Independent result arithmetic/checksum audit and small original-data ECG strips."""
import json
from pathlib import Path
import sys
import hashlib
import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'src'))
from sanad_ppg.dataset import load_recording


def main(folder):
    audit = {}
    for split in ['development', 'evaluation']:
        rows = [json.loads(line) for line in (ROOT/'reports'/split/'windows.jsonl').read_text().splitlines()]
        summary = json.loads((ROOT/'reports'/split/'summary.json').read_text())['aggregate']
        paired = [r for r in rows if r['status'] == 'ok' and r['ecg_reference']['status'] == 'ok']
        errors = np.asarray([r['hr_bpm']-r['ecg_reference']['hr_bpm'] for r in paired])
        assert summary['paired_windows'] == len(paired)
        assert abs(summary['mae_bpm']-float(np.mean(abs(errors)))) < 1e-12
        assert abs(summary['rmse_bpm']-float(np.sqrt(np.mean(errors**2)))) < 1e-12
        assert all(r['spo2_percent'] is None and not r['red_ir_features']['calibrated'] for r in rows)
        agreements = [r['ecg_reference']['quality']['event_f1_vs_alternate'] for r in rows]
        audit[split] = {'windows': len(rows), 'ecg_event_agreement_min': min(agreements),
                        'ecg_event_agreement_mean': float(np.mean(agreements)),
                        'primary_ecg_peak_count': sum(r['ecg_reference']['quality']['primary_peak_count'] for r in rows),
                        'alternate_ecg_peak_count': sum(r['ecg_reference']['quality']['alternate_peak_count'] for r in rows),
                        'metrics_independently_recomputed': True}
    inventory = json.loads((ROOT/'reports/dataset_inventory.json').read_text())
    for r in inventory['recordings']:
        for name, expected in [(r['file'], r['sha256']), (f"{r['session']}_indices_03.csv", r['indices_sha256'])]:
            assert hashlib.sha256((folder/name).read_bytes()).hexdigest() == expected
    audit['all_12_original_checksums_unchanged'] = True
    (ROOT/'reports/result_audit.json').write_text(json.dumps(audit, indent=2), encoding='utf8')
    figure, axes = plt.subplots(3, 1, figsize=(13, 8), layout='constrained')
    for ax, session in zip(axes, [0, 2, 5]):
        split = 'development' if session < 2 else 'evaluation'
        rows = [json.loads(line) for line in (ROOT/'reports'/split/'windows.jsonl').read_text().splitlines()]
        row = next(r for r in rows if r['recording_id'].endswith(f'/{session}'))
        t, channels = load_recording(folder, session)
        relative = t-t[0]
        mask = (relative >= 4) & (relative <= 12)
        peaks = np.asarray(row['ecg_reference']['r_peaks_s'])
        peaks = peaks[(peaks >= 4) & (peaks <= 12)]
        ax.plot(relative[mask], channels['ECG'][mask], lw=1, label='Supplied ECG (export units)')
        ax.scatter(peaks, np.interp(peaks, relative, channels['ECG']), c='red', s=20, label='Derived QRS locations')
        ax.set(title=f'Subject 03 / session {session}: fixed first-window strip, no manual ground truth', xlabel='Seconds from recording start', ylabel='ECG export units')
        ax.legend(loc='upper right'); ax.grid(alpha=.2)
    figure.savefig(ROOT/'reports/ecg_audit.png', dpi=130)
    plt.close(figure)
    print(json.dumps(audit, indent=2))


if __name__ == '__main__': main(Path(sys.argv[1]))
