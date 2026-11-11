<?php

return [
    'contract_version' => 1,
    'grace_policy_version' => 1,
    'grace_seconds' => 1800,
    'escalation_delay_minutes' => 30,
    'scheduler_enabled' => env('MEDICATION_SCHEDULER_ENABLED', false),
];
