"""Inspect filtering-context/timestamp alternatives and representative real traces.

All alternatives are diagnostics only. No candidate selection or frozen result is
rewritten. Plot normalization is visualization-only, not model preprocessing.
"""
from pathlib import Path
import json
import sys
import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

HERE = Path(__file__).resolve().parent
sys.path.insert(0,str(HERE.parent/'src'))
from sanad_ppg import Config,bandpass,process_recording
from sanad_ppg.dataset import load_recording
from analysis import CHANNELS,uniform_window,channel_view,summarize,dump,verify_preserved


def main(dataset):
    preservation = json.loads((HERE/'artifacts/preservation.json').read_text())
    rows = [json.loads(line) for line in (HERE/'artifacts/development/baseline_windows.jsonl').read_text().splitlines()]
    cache = {s:load_recording(dataset,s) for s in [0,1]}
    context = []
    row_time = []
    edge = 200
    for session,(t,channels) in cache.items():
        for channel,name in CHANNELS.items():
            # Different timestamp interpretation is not adopted; source-clock
            # semantics cannot be inferred solely from endpoint statistics.
            uniform_row_time = np.linspace(t[0],t[-1],len(t))
            alternate = process_recording(uniform_row_time,channel_view(channels,channel),Config())
            row_time.append({'session':session,'channel':channel,**summarize(alternate)})
            grid = t[0]+np.arange(round((t[-1]-t[0])*100)+1)/100
            full_filtered = bandpass(np.interp(grid,t,channels[name]),100)
            for row in rows:
                if row['review_session']!=session or row['review_channel']!=channel: continue
                x = uniform_window(t,channels[name],row)
                local = bandpass(x,100)[edge:-edge]
                start = row['window_start_s']
                central_time = start+np.arange(edge,len(x)-edge)/100
                contextual = np.interp(central_time,grid,full_filtered)
                context.append({'session':session,'channel':channel,'window':row['window_index'],
                    'baseline_status':row['status'],
                    'central_filter_context_nrmse':float(np.sqrt(np.mean((local-contextual)**2))/max(np.std(contextual),1e-30)),
                    'central_filter_context_correlation':float(np.corrcoef(local,contextual)[0,1])})
    dump(HERE/'artifacts/development/context_diagnostics.json',context)
    dump(HERE/'artifacts/development/uniform_row_time_diagnostic_only.json',row_time)
    categories = ['accepted','weak_or_distorted_waveform_evidence','mixed_or_unresolved']
    examples = [next(r for r in rows if r['review_channel']=='Green' and r['diagnostics']['category']==category) for category in categories]
    figure,axes = plt.subplots(4,3,figsize=(17,12),layout='constrained')
    selections = []
    for col,example in enumerate(examples):
        session = example['review_session']
        t,channels = cache[session]
        selections.append({'session':session,'window':example['window_index'],'selection':example['diagnostics']['category']})
        for row_index,channel in enumerate(['Green','Red','IR']):
            row = next(r for r in rows if r['review_session']==session and r['window_index']==example['window_index'] and r['review_channel']==channel)
            x = uniform_window(t,channels[CHANNELS[channel]],row)
            y = bandpass(x,100)
            scale = max(np.std(y[edge:-edge]),1e-30)
            relative = np.arange(len(x))/100
            ax = axes[row_index,col]
            ax.plot(relative,y/scale,lw=.7)
            peaks = np.asarray(row['diagnostics']['integer_peak_offsets_s'])
            ax.scatter(peaks,np.interp(peaks,relative,y/scale),s=8,c='red')
            ax.axvspan(0,2,color='grey',alpha=.2); ax.axvspan(28,30,color='grey',alpha=.2)
            ax.set(title=f"{channel}: {row['status']}; peaks={row['diagnostics']['peak_count']}",
                   ylabel='Filtered / central SD (display)',xlabel='Window-relative seconds',xlim=(0,30))
            ax.grid(alpha=.15)
        ax = axes[3,col]
        x = uniform_window(t,channels['ECG'],example)
        relative = np.arange(len(x))/100
        ax.plot(relative,x,lw=.6)
        peaks = np.asarray(example['ecg_reference']['r_peaks_s'])
        ax.scatter(peaks,np.interp(peaks,relative,x),s=8,c='red')
        ax.set(title=f"ECG-derived HR {example['ecg_reference']['hr_bpm']:.2f} bpm",xlabel='Window-relative seconds',ylabel='ECG export units',xlim=(0,30))
        axes[0,col].text(.5,1.2,f"Session {session}, window {example['window_index']}\n{example['diagnostics']['category']}",ha='center',transform=axes[0,col].transAxes,fontsize=10)
    figure.savefig(HERE/'artifacts/development/representative_waveforms.png',dpi=120)
    plt.close(figure)
    dump(HERE/'artifacts/development/plot_selection.json',selections)
    print(json.dumps({'uniform_row_time_diagnostic_only':row_time,
        'context_nrmse_by_channel':{c:{'median':float(np.median([r['central_filter_context_nrmse'] for r in context if r['channel']==c])),
                                     'maximum':max(r['central_filter_context_nrmse'] for r in context if r['channel']==c)} for c in CHANNELS}},indent=2))
    print('Preserved files:',verify_preserved(HERE.parent.parents[1],preservation))


if __name__=='__main__': main(sys.argv[1])
