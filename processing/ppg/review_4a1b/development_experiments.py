from dataclasses import asdict,replace
from pathlib import Path
import hashlib
import json
import sys
import numpy as np

HERE = Path(__file__).resolve().parent
sys.path.insert(0,str(HERE.parent/'src'))
from sanad_ppg import Config,process_recording
from sanad_ppg.dataset import load_recording
from analysis import CHANNELS,channel_view,uniform_window,summarize,dump,verify_preserved
from candidates import FRACTIONS,candidate_hr,choose_candidate


def key(row): return (row['review_session'],row['window_index'],row['review_channel'])


def compact(row):
    return {k:v for k,v in row.items() if k not in ['red_ir_features','diagnostics']} | {
        'ecg_reference':{k:v for k,v in row['ecg_reference'].items() if k!='r_peaks_s'}}


def compare(new,baseline,name,channel):
    old = {key(r):r for r in baseline if r['review_channel']==channel}
    rows = [r for r in new if r['review_channel']==channel]
    newly = [r for r in rows if r['status']=='ok' and old[key(r)]['status']!='ok']
    errors = [abs(r['hr_bpm']-r['ecg_reference']['hr_bpm']) for r in newly if r['ecg_reference']['status']=='ok']
    return {'candidate':name,'channel':channel,**summarize(rows),
            'lost_previous':sum(r['status']!='ok' and old[key(r)]['status']=='ok' for r in rows),
            'new_accepted':len(newly),'new_errors_gt3':sum(e>3 for e in errors),
            'new_max_abs_error_bpm':max(errors) if errors else None,
            'baseline_max_abs_error_bpm':summarize(list(old.values()))['max_abs_error_bpm']}


def main(dataset):
    dataset = Path(dataset)
    preservation = json.loads((HERE/'artifacts/preservation.json').read_text())
    verify_preserved(HERE.parent.parents[1],preservation)
    baseline = [json.loads(line) for line in (HERE/'artifacts/development/baseline_windows.jsonl').read_text().splitlines()]
    cache = {s:load_recording(dataset,s) for s in [0,1]}
    results,comparisons = {},[]
    for fraction in FRACTIONS:
        name = f'guided_{fraction:.2f}'
        rows = []
        for old in baseline:
            t,channels = cache[old['review_session']]
            x = uniform_window(t,channels[CHANNELS[old['review_channel']]],old)
            estimate = candidate_hr(x,100,Config(),fraction)
            row = {**compact(old),**estimate}
            rows.append(row)
        results[name] = rows
        comparisons += [compare(rows,baseline,name,c) for c in CHANNELS]
    settings = [
        ('rr_cv_0.15',replace(Config(),max_rr_cv=.15)),('rr_cv_0.25',replace(Config(),max_rr_cv=.25)),
        ('acf_0.30',replace(Config(),min_autocorrelation=.30)),('acf_0.50',replace(Config(),min_autocorrelation=.50)),
        ('concentration_0.35',replace(Config(),min_spectral_concentration=.35)),
        ('concentration_0.55',replace(Config(),min_spectral_concentration=.55)),
    ]
    sensitivity = []
    for name,cfg in settings:
        rows = []
        for s,(t,channels) in cache.items():
            for c in CHANNELS:
                for row in process_recording(t,channel_view(channels,c),cfg):
                    row.update({'review_channel':c,'review_session':s})
                    rows.append(compact(row))
        results[name] = rows
        sensitivity += [{**compare(rows,baseline,name,c),'config':asdict(cfg),'diagnostic_only_not_promotable':True} for c in CHANNELS]
    folder = HERE/'artifacts/development'
    dump(folder/'candidate_comparisons.json',comparisons)
    dump(folder/'gate_sensitivity.json',sensitivity)
    for name,rows in results.items():
        (folder/f'{name}_windows.jsonl').write_text(''.join(json.dumps(r,allow_nan=False)+'\n' for r in rows),encoding='utf8')
    baseline_counts = {c:summarize([r for r in baseline if r['review_channel']==c])['accepted'] for c in ['Red','IR']}
    choice = choose_candidate(comparisons,baseline_counts)
    frozen = {'selected_candidate':choice,'revised_processor_release':choice is not None,
              'reason':'Development-only predeclared reliability screen; no retrospective data used to choose',
              'fractions':list(FRACTIONS),'baseline_config':asdict(Config()),
              'code_sha256':{name:hashlib.sha256((HERE/name).read_bytes()).hexdigest() for name in ['analysis.py','candidates.py','development_experiments.py']},
              'baseline_preservation_file':'preservation.json','retrospective_sessions':[2,3,4,5],
              'retrospective_is_not_untouched_holdout':True}
    freeze = HERE/'artifacts/development_decision.json'
    if freeze.exists() and json.loads(freeze.read_text()) != frozen:
        raise ValueError('Frozen development decision/code changed; create a separately documented revision.')
    dump(freeze,frozen)
    print(json.dumps({'candidate_comparisons':comparisons,'decision':frozen},indent=2))
    print('Protected files unchanged:',verify_preserved(HERE.parent.parents[1],preservation))


if __name__=='__main__': main(sys.argv[1])
