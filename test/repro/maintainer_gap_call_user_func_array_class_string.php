<?php

declare(strict_types=1);

/**
 * Maintainer repro: call_user_func_array() Class::method string callable (#11694).
 */

class CufaGlobalClassMethodProbe
{
    public static function ok(): string
    {
        return 'ok';
    }
}

echo call_user_func_array(CufaGlobalClassMethodProbe::class.'::ok', []), "\n";
