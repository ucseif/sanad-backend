"""Actual dataset/provenance and output-contract checks, separate from analytic tests."""
import csv
import hashlib
import json
from pathlib import Path
import numpy as np
import pytest
from jsonschema import Draft202012Validator, ValidationError
from sanad_ppg import Config, process_recording
from sanad_ppg.dataset import load_recording, validate_indices
from sanad_ppg.processor import _match_fraction

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT.parents[1]/'datasets/WF-PPG/03'


def test_json_schema_and_all_actual_report_records():
    schema = json.loads((ROOT/'output.schema.json').read_text())
    Draft202012Validator.check_schema(schema)
    validator = Draft202012Validator(schema)
    count = 0
    for split in ['development', 'evaluation']:
        rows = [json.loads(line) for line in (ROOT/'reports'/split/'windows.jsonl').read_text().splitlines()]
        for row in rows:
            validator.validate(row)
            assert row['window_start_s'] < row['analysis_start_s'] < row['analysis_end_s'] < row['window_end_s']
            json.dumps(row, allow_nan=False)
        count += len(rows)
    assert count == 47
    bad = dict(rows[0], spo2_percent=98.)
    with pytest.raises(ValidationError): validator.validate(bad)
    bad = dict(rows[0], status='rejected', hr_bpm=80., reasons=['bad'])
    with pytest.raises(ValidationError): validator.validate(bad)


def test_incomplete_and_missing_sensor_outputs_also_obey_contract():
    t = np.arange(6500)/100
    rows = process_recording(t, {'PPG_Wrist_G': np.sin(2*np.pi*1.2*t)}, Config())
    validator = Draft202012Validator(json.loads((ROOT/'output.schema.json').read_text()))
    for row in rows: validator.validate(row)
    assert rows[-1]['reasons'] == ['incomplete_window']
    assert rows[0]['red_ir_features']['status'] == 'unavailable'


def test_one_to_one_ecg_event_matching_cannot_reuse_a_peak():
    assert _match_fraction([1., 1.01], [1.], .08) == 2/3
    assert _match_fraction([1., 2.], [1.5, 2.5], .08) == 0


def test_actual_files_alignment_indices_checksums_and_frozen_code():
    inventory = json.loads((ROOT/'reports/dataset_inventory.json').read_text())
    for entry in inventory['recordings']:
        session = entry['session']
        t, channels = load_recording(DATA, session)
        assert np.isfinite(t).all() and np.all(np.diff(t) > 0)
        assert all(len(v) == len(t) and np.isfinite(v).all() for v in channels.values())
        assert len(validate_indices(DATA, session, len(t))) == entry['indices_rows']
        for name, checksum in [(entry['file'], entry['sha256']), (f'{session}_indices_03.csv', entry['indices_sha256'])]:
            assert hashlib.sha256((DATA/name).read_bytes()).hexdigest() == checksum
    frozen = json.loads((ROOT/'reports/frozen_baseline.json').read_text())
    code = b''.join(p.read_bytes() for p in sorted((ROOT/'src/sanad_ppg').glob('*.py')))
    assert hashlib.sha256(code).hexdigest() == frozen['code_sha256']
    assert set(frozen['development_sessions']).isdisjoint(frozen['evaluation_sessions'])


def test_nonfinite_optional_channel_is_not_hidden_by_interpolation():
    t = np.arange(3000)/100
    t[101] = t[100] + 1e-7
    red = 100 + np.sin(2*np.pi*1.2*t)
    red[101] = np.nan
    row = process_recording(t, {'PPG_Wrist_G': np.sin(2*np.pi*1.2*t), 'PPG_Wrist_R': red, 'PPG_Wrist_IR': red.copy()}, Config())[0]
    assert row['status'] == 'ok'
    assert row['red_ir_features']['status'] == 'unavailable'


def test_dataset_adapter_rejects_missing_files_and_wrong_headers(tmp_path):
    with pytest.raises(FileNotFoundError): load_recording(tmp_path, 0)
    # Deliberately malformed header fixture, never a simulated WF-PPG recording.
    (tmp_path/'0_preprocessed_03.csv').write_text('Time,ECG\n0,1\n')
    with pytest.raises(ValueError): load_recording(tmp_path, 0)


def test_metrics_recomputed_from_actual_outputs_and_do_not_count_rejected_windows_as_zero_error():
    for split in ['development', 'evaluation']:
        rows = [json.loads(line) for line in (ROOT/'reports'/split/'windows.jsonl').read_text().splitlines()]
        summary = json.loads((ROOT/'reports'/split/'summary.json').read_text())['aggregate']
        pairs = [r for r in rows if r['status'] == 'ok' and r['ecg_reference']['status'] == 'ok']
        errors = np.asarray([r['hr_bpm']-r['ecg_reference']['hr_bpm'] for r in pairs])
        assert summary['mae_bpm'] == pytest.approx(np.mean(abs(errors)))
        assert summary['rmse_bpm'] == pytest.approx(np.sqrt(np.mean(errors**2)))
        assert summary['paired_coverage_all'] == len(pairs)/len(rows)
