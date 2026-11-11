import argparse
from collections import Counter
from pathlib import Path
import json
import sys

HERE = Path(__file__).resolve().parent
BASE = HERE.parent
REPO = BASE.parents[1]
sys.path.insert(0,str(BASE/'src'))
from sanad_ppg import Config, process_recording
from sanad_ppg.dataset import load_recording
from analysis import CHANNELS, channel_view, preservation_snapshot, verify_preserved, summarize, diagnose, uniform_window, dump


def snapshot(paths):
    target = HERE/'artifacts/preservation.json'
    target.parent.mkdir(parents=True,exist_ok=True)
    if target.exists():
        prior = json.loads(target.read_text())
        verify_preserved(REPO,prior)
        return prior
    result = preservation_snapshot(REPO,paths)
    dump(target,result)
    return result


def protected_paths(dataset):
    paths = [p for p in BASE.rglob('*') if p.is_file() and HERE not in p.parents and not any(s in p.parts for s in ['.venv','__pycache__','.pytest_cache'])]
    paths += list(dataset.glob('*.csv'))
    for name in ['app','bootstrap','config','routes','database/migrations']:
        paths += [p for p in (REPO/name).rglob('*') if p.is_file()]
    paths += [REPO/'.gitignore',REPO/'composer.json',REPO/'composer.lock']
    return list(set(paths))


def baseline_rows(dataset,sessions):
    rows = []
    for session in sessions:
        t, channels = load_recording(dataset,session)
        for channel in CHANNELS:
            selected = channel_view(channels,channel)
            results = process_recording(t,selected,Config(),recording_id=f'WF-PPG/03/{session}')
            for row in results:
                row['review_channel'] = channel
                row['review_session'] = session
                x = uniform_window(t,channels[CHANNELS[channel]],row)
                row['diagnostics'] = diagnose(x,row)
            rows += results
    return rows


def save_baseline(rows,destination):
    destination.mkdir(parents=True,exist_ok=True)
    (destination/'baseline_windows.jsonl').write_text(''.join(json.dumps(row,allow_nan=False)+'\n' for row in rows),encoding='utf8')
    summary = {c:summarize([r for r in rows if r['review_channel']==c]) for c in CHANNELS}
    root_causes = {c:dict(Counter(r['diagnostics']['category'] for r in rows if r['review_channel']==c)) for c in CHANNELS}
    dump(destination/'baseline_summary.json',summary)
    dump(destination/'diagnostic_categories.json',root_causes)
    return summary


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('dataset',type=Path)
    args = parser.parse_args()
    dataset = args.dataset.resolve()
    prior = snapshot(protected_paths(dataset))
    summary = save_baseline(baseline_rows(dataset,[0,1]),HERE/'artifacts/development')
    print(json.dumps(summary,indent=2))
    print('Verified preserved files:',verify_preserved(REPO,prior))


if __name__=='__main__': main()
