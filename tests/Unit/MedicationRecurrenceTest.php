<?php

namespace Tests\Unit;

use App\Application\Medication\Recurrence;
use Carbon\CarbonImmutable as Time;
use PHPUnit\Framework\TestCase;

class MedicationRecurrenceTest extends TestCase
{
    private function schedule(array $changes = []): array
    {
        return array_replace(['frequency' => 'once_daily', 'timezone' => 'UTC', 'start_date' => '2026-01-01',
            'end_date' => null, 'times' => ['08:00'], 'weekdays' => [], 'interval_hours' => null,
            'anchor_time' => null, 'effective_from' => '2026-01-01T00:00:00Z', 'effective_to' => null], $changes);
    }

    public function test_daily_and_specific_weekdays_and_inclusive_end(): void
    {
        $engine = new Recurrence;
        $start = Time::parse('2026-11-09T00:00:00Z');
        $end = $start->addDays(3);
        $items = $engine->between($this->schedule(['frequency' => 'specific_days', 'weekdays' => [1, 3],
            'times' => ['08:00', '20:00'], 'end_date' => '2026-11-11']), $start, $end);
        $this->assertCount(4, $items);
        $this->assertSame('2026-11-11T20:00:00+00:00', $items[3]['scheduled_at']);
        foreach (['once_daily' => 1, 'twice_daily' => 2, 'three_times_daily' => 3] as $frequency => $count) {
            $this->assertCount($count, $engine->between($this->schedule(['frequency' => $frequency,
                'times' => array_slice(['08:00', '14:00', '20:00'], 0, $count)]), $start, $start->addDay()));
        }
    }

    public function test_elapsed_interval_crosses_midnight_and_prn_has_no_occurrences(): void
    {
        $engine = new Recurrence;
        $s = $this->schedule(['frequency' => 'every_hours', 'start_date' => '2026-11-09', 'anchor_time' => '22:00', 'interval_hours' => 4, 'times' => []]);
        $items = $engine->between($s, Time::parse('2026-11-09'), Time::parse('2026-11-11'));
        $this->assertSame('2026-11-10T02:00:00+00:00', $items[1]['scheduled_at']);
        $this->assertSame([], $engine->between($this->schedule(['frequency' => 'as_needed']), Time::parse('2026-01-01'), Time::parse('2026-01-02')));
    }

    public function test_dst_gap_collision_and_overlap_are_deterministic(): void
    {
        $engine = new Recurrence;
        $s = $this->schedule(['timezone' => 'America/New_York', 'times' => ['02:30', '03:30']]);
        $items = $engine->between($s, Time::parse('2026-03-08T00:00:00Z'), Time::parse('2026-03-09T00:00:00Z'));
        $this->assertCount(1, $items);
        $this->assertSame('2026-03-08T07:30:00+00:00', $items[0]['scheduled_at']);
        $this->assertCount(2, $items[0]['requested_local_times']);
        $items = $engine->between($this->schedule(['timezone' => 'America/New_York', 'times' => ['01:30']]), Time::parse('2026-11-01'), Time::parse('2026-11-02'));
        $this->assertCount(1, $items);
        $this->assertSame('2026-11-01T05:30:00+00:00', $items[0]['scheduled_at']);
    }

    public function test_leap_year_and_effective_boundaries(): void
    {
        $engine = new Recurrence;
        $s = $this->schedule(['start_date' => '2028-02-28', 'end_date' => '2028-03-01', 'effective_from' => '2028-02-29T08:00:00Z', 'effective_to' => '2028-03-01T08:00:00Z']);
        $this->assertCount(1, $engine->between($s, Time::parse('2028-02-28'), Time::parse('2028-03-02')));
    }
}
