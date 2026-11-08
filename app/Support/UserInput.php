<?php

namespace App\Support;

final class UserInput
{
    /** Unicode White_Space plus BOM, matching Dart String.trim(). */
    public static function trim(string $value): string
    {
        $whitespace = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

        return preg_replace('/\A'.$whitespace.'+|'.$whitespace.'+\z/u', '', $value) ?? $value;
    }
}
