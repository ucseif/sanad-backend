<?php

namespace App\Application\Medication;

use Carbon\CarbonImmutable as Time;
use DateTimeZone;

/** Pure recurrence expansion. Bounds are absolute UTC instants, [from,to). */
final class Recurrence
{
    public function resolve(string $date, string $clock, string $timezone): Time
    {
        $wall = Time::parse($date.' '.$clock, 'UTC')->getTimestamp();
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall - 172800, $wall + 172800);
        $matches = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $instant = Time::createFromTimestampUTC($wall - $offset);
            if ($instant->setTimezone($zone)->format('Y-m-d H:i') === $date.' '.substr($clock, 0, 5)) {
                $matches[] = $instant;
            }
        }
        if ($matches !== []) {
            usort($matches, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

            return $matches[0];
        }
        // Missing wall clock: explicitly shift forward by the transition's gap.
        $previous = $transitions[0]['offset'];
        foreach (array_slice($transitions, 1) as $transition) {
            if ($transition['offset'] > $previous && $wall >= $transition['ts'] + $previous && $wall < $transition['ts'] + $transition['offset']) {
                return Time::createFromTimestampUTC($wall - $previous);
            }
            $previous = $transition['offset'];
        }
        throw new \InvalidArgumentException('Unresolvable local time.');
    }

    public function between(array $schedule, Time $from, Time $to): array
    {
        if ($schedule['frequency'] === 'as_needed') {
            return [];
        }
        $timezone = $schedule['timezone'];
        $lower = $from->max(Time::parse($schedule['effective_from']));
        $upper = isset($schedule['effective_to']) ? $to->min(Time::parse($schedule['effective_to'])) : $to;
        if ($lower >= $upper) {
            return [];
        }
        $items = [];
        $add = function (Time $instant, string $requested) use (&$items, $schedule, $timezone, $lower, $upper): void {
            $local = $instant->setTimezone($timezone);
            $date = $local->format('Y-m-d');
            if ($instant < $lower || $instant >= $upper || $date < $schedule['start_date'] || (isset($schedule['end_date']) && $date > $schedule['end_date'])) {
                return;
            }
            $key = $instant->getTimestamp();
            if (! isset($items[$key])) {
                $items[$key] = ['scheduled_at' => $instant->utc()->toIso8601String(), 'local_date' => $date,
                    'local_time' => substr($requested, 11, 5), 'resolved_local_time' => $local->toIso8601String(),
                    'requested_local_times' => []];
            }
            $items[$key]['requested_local_times'][] = $requested;
        };
        if ($schedule['frequency'] === 'every_hours') {
            $anchor = isset($schedule['anchor_at']) ? Time::parse($schedule['anchor_at']) : $this->resolve($schedule['start_date'], $schedule['anchor_time'], $timezone);
            $step = $schedule['interval_hours'] * 3600;
            $index = max(0, (int) ceil(($lower->getTimestamp() - $anchor->getTimestamp()) / $step));
            for ($instant = $anchor->addSeconds($index * $step); $instant < $upper; $instant = $instant->addSeconds($step)) {
                $add($instant, $instant->setTimezone($timezone)->format('Y-m-d H:i'));
            }
        } else {
            // UTC dates are used as wall-calendar arithmetic, never as patient instants.
            $first = Time::parse($lower->setTimezone($timezone)->format('Y-m-d'), 'UTC')->subDay();
            $last = Time::parse($upper->setTimezone($timezone)->format('Y-m-d'), 'UTC');
            for ($day = $first; $day <= $last; $day = $day->addDay()) {
                if ($schedule['frequency'] === 'specific_days' && ! in_array($day->isoWeekday(), $schedule['weekdays'], true)) {
                    continue;
                }
                foreach ($schedule['times'] as $clock) {
                    $date = $day->format('Y-m-d');
                    $add($this->resolve($date, $clock, $timezone), $date.' '.substr($clock, 0, 5));
                }
            }
        }
        ksort($items);

        return array_values($items);
    }
}
