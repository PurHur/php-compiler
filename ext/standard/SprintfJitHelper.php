<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * sprintf()/printf()/number_format() for compiled JIT/AOT modules (#9131, php-in-PHP).
 *
 * SSOT: {@see VmSprintf}, {@see VmNumberFormat}
 * php-src: ext/standard/sprintf.c, ext/standard/number_format.c
 */
final class SprintfJitHelper
{
    /**
     * @param string $packedArgs length-prefixed argv blob from {@see PackArgvSerialize}
     */
    public static function sprintfArgv(string $format, string $packedArgs): string
    {
        return VmSprintf::format($format, self::variablesFromPackedArgv($packedArgs));
    }

    public static function numberFormat(
        float $number,
        int $decimals,
        string $decimalSeparator,
        string $thousandsSeparator
    ): string {
        return VmNumberFormat::format($number, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * @return list<Variable>
     */
    private static function variablesFromPackedArgv(string $packed): array
    {
        $vars = [];
        foreach (PackJitHelper::decodePackedArgv($packed) as $scalar) {
            $var = new Variable();
            if (null === $scalar) {
                $var->null();
            } elseif (\is_int($scalar)) {
                $var->int($scalar);
            } elseif (\is_float($scalar)) {
                $var->float($scalar);
            } elseif (\is_bool($scalar)) {
                $var->bool($scalar);
            } elseif (\is_string($scalar)) {
                $var->string($scalar);
            } else {
                $var->null();
            }
            $vars[] = $var;
        }

        return $vars;
    }
}
