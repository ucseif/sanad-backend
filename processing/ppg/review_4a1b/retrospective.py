"""Already inspected sessions: descriptive retrospective comparison, never holdout."""
from pathlib import Path
import hashlib
import json
import sys

HERE = Path(__file__).resolve().parent
sys.path.insert(0,str(HERE.parent/'src'))
from sanad_ppg import Config
from sanad_ppg.dataset import load_recording
from analysis import CHANNELS,uniform_window,dump,verify_preserved
from candidates import FRACTIONS,candidate_hr
from development_experiments import compact,compare
from run_review import baseline_rows,save_baseline


def main(dataset):
    preservation = json.loads((HERE/'artifacts/preservation.json').read_text())
    verify_preserved(HERE.parent.parents[1],preservation)
    decision = json.loads((HERE/'artifacts/development_decision.json').read_text())
    for name,digest in decision['code_sha256'].items():
        if hashlib.sha256((HERE/name).read_bytes()).hexdigest()!=digest:
            raise ValueError('Development code changed after decision freeze.')
    rows = baseline_rows(Path(dataset),[2,3,4,5])
    folder = HERE/'artifacts/retrospective'
    summary = save_baseline(rows,folder)
    cache = {s:load_recording(dataset,s) for s in [2,3,4,5]}
    comparisons = []
    for fraction in FRACTIONS:
        name = f'guided_{fraction:.2f}'
        candidate_rows = []
        for old in rows:
            t,channels = cache[old['review_session']]
            x = uniform_window(t,channels[CHANNELS[old['review_channel']]],old)
            candidate_rows.append({**compact(old),**candidate_hr(x,100,Config(),fraction)})
        comparisons += [{**compare(candidate_rows,rows,name,c),'retrospective_not_holdout':True} for c in CHANNELS]
        (folder/f'{name}_windows.jsonl').write_text(''.join(json.dumps(r,allow_nan=False)+'\n' for r in candidate_rows),encoding='utf8')
    dump(folder/'candidate_comparisons.json',comparisons)
    dump(folder/'interpretation.json',{'retrospective_not_untouched_holdout':True,
        'development_decision_unchanged':decision,'baseline':summary,
        'no_retrospective_model_or_threshold_selection':True,
        'preserved_files':verify_preserved(HERE.parent.parents[1],preservation)})
    print(json.dumps({'baseline':summary,'candidate_comparisons':comparisons},indent=2))


if __name__=='__main__': main(sys.argv[1])
