<?php

namespace Tests\Support;

final class MedicationTables
{
    public static function create(): void
    {
        CaregiverLinkTables::create();
        (require database_path('migrations/phase6/2026_11_11_000000_create_medication_management.php'))->up();
    }
}
