<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * vsprintf() for compiled JIT/AOT modules (#15989, php-in-PHP).
 *
 * SSOT: {@see SprintfJitHelper::sprintfArgv()} after {@see PackArgvSerialize} packs values.
 * php-src: ext/standard/sprintf.c — PHP_FUNCTION(vsprintf)
 */
final class VsprintfJitHelper
{
    /**
     * @param string $packedArgv length-prefixed argv blob from {@see PackArgvSerialize}
     */
    public static function formatPackedArgv(string $format, string $packedArgv): string
    {
        return SprintfJitHelper::sprintfArgv($format, $packedArgv);
    }
}
