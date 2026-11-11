<?php

namespace App\Http\Requests;

use App\Application\Medication\MedicationFailure;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class MedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()->role === UserRole::Caregiver && ($this->is('*/escalation-consent')
            || $this->exists('reminder_preferences.notify_caregiver_if_missed')
            || $this->exists('reminder_preferences.escalation_delay_minutes'))) {
            throw new MedicationFailure('forbidden', 403);
        }

        return true;
    }

    public function rules(): array
    {
        $op = str_replace('medication.', '', $this->route()->getName());
        $version = ['expected_version' => ['required', 'integer', 'min:1']];
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        $date = ['date' => ['required', 'date_format:Y-m-d'], 'timezone' => ['required', 'timezone:all']];
        $range = ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'], 'timezone' => ['required', 'timezone:all']];
        $prescription = function (bool $create): array {
            $required = $create ? 'required' : 'sometimes';

            return ['prescription' => [$required, 'array:name,dose_amount,dose_unit,form,relation_to_meals,instructions,special_notes'],
                'prescription.name' => [$required, 'string', 'min:1', 'max:160'],
                'prescription.dose_amount' => [$required, 'string', 'regex:/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/', 'numeric', 'gt:0'],
                'prescription.dose_unit' => [$required, Rule::in(['mg', 'g', 'ml', 'mcg', 'IU', 'tablet', 'capsule', 'drop', 'puff'])],
                'prescription.form' => [$required, Rule::in(['tablet', 'capsule', 'syrup', 'drops', 'injection', 'inhaler', 'cream', 'other'])],
                'prescription.relation_to_meals' => [$required, Rule::in(['before_food', 'after_food', 'with_food', 'no_preference'])],
                'prescription.instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'prescription.special_notes' => ['sometimes', 'nullable', 'string', 'max:4000']];
        };
        $schedule = function (bool $create): array {
            $required = $create ? 'required' : 'sometimes';

            return ['schedule' => [$required, 'array:frequency,timezone,start_date,end_date,times,weekdays,interval_hours,anchor_time'],
                'schedule.frequency' => ['required_with:schedule', Rule::in(['once_daily', 'twice_daily', 'three_times_daily', 'specific_days', 'every_hours', 'as_needed'])],
                'schedule.timezone' => ['sometimes', 'timezone:all'],
                'schedule.start_date' => ['required_with:schedule', 'date_format:Y-m-d'],
                'schedule.end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:schedule.start_date'],
                'schedule.times' => ['sometimes', 'array', 'max:24'], 'schedule.times.*' => ['required', 'date_format:H:i', 'distinct:strict'],
                'schedule.weekdays' => ['sometimes', 'array', 'max:7'], 'schedule.weekdays.*' => ['integer', 'between:1,7', 'distinct:strict',
                    function ($attribute, $value, $fail): void {
                        if (! is_int($value)) {
                            $fail('Weekdays must be JSON integers.');
                        }
                    }],
                'schedule.interval_hours' => ['nullable', 'integer', 'between:1,168'], 'schedule.anchor_time' => ['nullable', 'date_format:H:i']];
        };

        return match ($op) {
            'settings' => [],
            'settings-update' => ['timezone' => ['required', 'timezone:all'], 'expected_version' => ['required', 'integer', 'min:0']],
            'index' => [...$page, 'status' => ['sometimes', Rule::in(['active', 'archived', 'all'])]],
            'store' => [...$prescription(true), ...$schedule(true),
                'reminder_preferences' => ['sometimes', 'array:enable_medication_reminders,notify_caregiver_if_missed,escalation_delay_minutes'],
                'reminder_preferences.enable_medication_reminders' => ['sometimes', 'boolean'],
                'reminder_preferences.notify_caregiver_if_missed' => ['sometimes', 'boolean'],
                'reminder_preferences.escalation_delay_minutes' => ['sometimes', Rule::in([15, 30, 60])]],
            'update' => [...$version, ...$prescription(false), ...$schedule(false)],
            'archive' => $version,
            'revisions' => $page,
            'reminders' => [...$version, 'enable_medication_reminders' => ['required', 'boolean']],
            'consent' => [...$version, 'notify_caregiver_if_missed' => ['required', 'boolean'], 'escalation_delay_minutes' => ['sometimes', Rule::in([15, 30, 60])]],
            'dashboard' => [...$date, ...$page, 'missed_page' => ['sometimes', 'integer', 'min:1', 'max:100000']],
            'doses', 'adherence' => [...$range, ...$page],
            'taken', 'late-intake', 'prn' => [...$version, 'actual_intake_at' => ['sometimes', 'date_format:Y-m-d\TH:i:sP']],
            'skipped' => $version,
            'corrections' => [...$version, 'target_status' => ['required', Rule::in(['taken', 'skipped', 'unresolved', 'void'])],
                'actual_intake_at' => ['sometimes', 'date_format:Y-m-d\TH:i:sP'], 'reason' => ['required', 'string', 'min:1', 'max:1000']],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn ($key) => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'This field is not accepted.');
            }
            if (! $this->isMethod('GET') && ! Str::isUuid($this->header('Idempotency-Key', ''))) {
                $validator->errors()->add('Idempotency-Key', 'A UUID idempotency key is required.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->has('schedule') && is_array($this->input('schedule'))) {
                $s = $this->input('schedule');
                $f = $s['frequency'] ?? '';
                $times = $s['times'] ?? [];
                $days = $s['weekdays'] ?? [];
                $count = ['once_daily' => 1, 'twice_daily' => 2, 'three_times_daily' => 3][$f] ?? null;
                if (($count !== null && count($times) !== $count) || ($f === 'specific_days' && (count($times) < 1 || count($days) < 1))
                    || (in_array($f, ['every_hours', 'as_needed'], true) && $times !== [])
                    || ($f !== 'specific_days' && $days !== [])
                    || ($f === 'every_hours' && (empty($s['interval_hours']) || empty($s['anchor_time'])))
                    || ($f !== 'every_hours' && (! empty($s['interval_hours']) || ! empty($s['anchor_time'])))) {
                    $validator->errors()->add('schedule', 'Frequency fields are inconsistent.');
                }
            }
        }];
    }
}
