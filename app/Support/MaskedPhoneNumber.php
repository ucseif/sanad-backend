<?php

namespace App\Support;

final class MaskedPhoneNumber
{
    public static function fromInternational(string $phone): string
    {
        // Calling codes currently supported by SANAD's existing country picker.
        $callingCode = preg_match('/\A\+(966|971|965|974|968|973|962|20|44|1)[0-9]+\z/', $phone, $matches)
            ? '+'.$matches[1].' ' : '';

        return $callingCode.'•••••• '.substr($phone, -4);
    }
}
