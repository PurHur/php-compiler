<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strftime()/gmstrftime() for compiled JIT/AOT modules (#9132, php-in-PHP).
 *
 * SSOT: {@see VmDate::strftime()} / {@see VmDate::gmstrftime()}
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(strftime), PHP_FUNCTION(gmstrftime)
 */
final class StrftimeJitHelper
{
    public static function strftimeArgv(string $format, int $timestamp, int $gmt): string
    {
        if (0 !== $gmt) {
            return VmDate::gmstrftime($format, $timestamp);
        }

        return VmDate::strftime($format, $timestamp);
    }
}
