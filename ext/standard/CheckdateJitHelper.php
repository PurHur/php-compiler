<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for checkdate() calendar validation (#9242, php-in-PHP).
 *
 * php-src: ext/standard/datetime.c PHP_FUNCTION(checkdate)
 * SSOT: {@see VmCheckdate::validate()}
 */
final class CheckdateJitHelper
{
    public static function checkdate(int $month, int $day, int $year): bool
    {
        return VmCheckdate::validate($month, $day, $year);
    }
}
