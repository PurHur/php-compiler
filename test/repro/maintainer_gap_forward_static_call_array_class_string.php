<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward_static_call_array() Class::method string at global scope (#11693).
 */

class FscGlobalClassMethodProbe
{
    public static function ok(): string
    {
        return 'ok';
    }
}

echo forward_static_call_array('FscGlobalClassMethodProbe::ok', []), "\n";
