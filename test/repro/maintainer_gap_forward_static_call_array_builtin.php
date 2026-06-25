<?php

declare(strict_types=1);

/**
 * Maintainer repro: forward_static_call_array() class scope builtin string (#11667).
 */

final class ForwardStaticBuiltinProbe
{
    public static function run(): int
    {
        return forward_static_call_array('strlen', ['abc']);
    }
}

echo ForwardStaticBuiltinProbe::run(), "\n";
