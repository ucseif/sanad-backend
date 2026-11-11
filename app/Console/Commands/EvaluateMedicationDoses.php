<?php

namespace App\Console\Commands;

use App\Application\Medication\DoseMaterializer;
use App\Application\Medication\MedicationEvaluator;
use App\Models\User;
use Carbon\CarbonImmutable as Time;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class EvaluateMedicationDoses extends Command
{
    protected $signature = 'medication:evaluate {--patient=} {--after-patient=0} {--limit=100} {--materialize-only} {--cycle}';

    protected $description = 'Bounded medication catch-up and eligibility reconciliation; never delivers notifications';

    public function handle(): int
    {
        if ($this->option('cycle')) {
            if ($this->option('patient') !== null || (string) $this->option('after-patient') !== '0') {
                $this->error('Cycle mode cannot be combined with a patient or manual cursor.');

                return self::FAILURE;
            }

            return Cache::lock('medication:evaluate:cycle-lock', 600)->get(fn () => $this->batch()) ?? self::SUCCESS;
        }

        return $this->batch();
    }

    private function batch(): int
    {
        $limit = $this->option('limit');
        $after = $this->option('cycle') ? Cache::get('medication:evaluate:cursor', 0) : $this->option('after-patient');
        $patient = $this->option('patient');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100 || ! ctype_digit((string) $after)
            || ($patient !== null && (! ctype_digit((string) $patient) || (int) $patient < 1))) {
            $this->error('Use a limit of 1..100 and positive patient identifiers.');

            return self::FAILURE;
        }
        $query = DB::table('medications')->select('patient_id')->distinct()->orderBy('patient_id');
        $patient !== null ? $query->where('patient_id', $patient) : $query->where('patient_id', '>', (int) $after);
        $ids = $query->limit((int) $limit)->pluck('patient_id');
        if ($this->option('cycle') && $ids->isEmpty() && (int) $after > 0) {
            $ids = DB::table('medications')->select('patient_id')->distinct()->orderBy('patient_id')->limit((int) $limit)->pluck('patient_id');
        }
        $totals = ['patients' => 0, 'generated' => 0, 'evaluated' => 0, 'delivered' => 0, 'next_after_patient' => (int) $after];
        $now = Time::now('UTC');
        foreach ($ids as $id) {
            if ($this->option('materialize-only')) {
                $result = DB::transaction(function () use ($id, $now): array {
                    User::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                    return ['generated' => app(DoseMaterializer::class)->catchUp($id, $now), 'evaluated' => 0];
                });
            } else {
                $result = app(MedicationEvaluator::class)->evaluatePatient($id, $now);
            }
            $totals['patients']++;
            $totals['generated'] += $result['generated'];
            $totals['evaluated'] += $result['evaluated'];
            $totals['next_after_patient'] = $id;
        }
        if ($this->option('cycle')) {
            Cache::forever('medication:evaluate:cursor', $totals['next_after_patient']);
        }
        $this->line(json_encode($totals, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
