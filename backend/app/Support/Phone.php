<?php

namespace App\Support;

class Phone
{
    /** Validation regex for Sri Lankan mobile numbers: 07XXXXXXXX, 947XXXXXXXX or +947XXXXXXXX */
    public const SL_MOBILE_REGEX = '/^(?:\+94|94|0)7\d{8}$/';

    /** Any Sri Lankan number, including landlines such as 0112345678 (for institutes/offices). */
    public const SL_ANY_REGEX = '/^(?:\+94|94|0)[1-9]\d{8}$/';

    /** Normalise to the 94XXXXXXXXX format required by local SMS gateways. */
    public static function normalize(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '94'.substr($digits, 1);
        }
        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            return $digits;
        }
        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '94'.$digits;
        }

        return null;
    }
}
