<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;

/**
 * Shared VM argument parsing for date_sunrise()/date_sunset() (ext/date/php_date.c, #6137).
 */
final class VmDateSunFunc
{
    /**
     * @return array{
     *     timestamp: int,
     *     returnFormat: int,
     *     latitude: ?float,
     *     longitude: ?float,
     *     zenith: ?float,
     *     gmtOffset: ?float,
     *     argc: int
     * }
     */
    public static function parseArgs(Frame $frame, string $function): array
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError($function.'() expects at least 1 argument, 0 given');
        }
        if ($argc > 6) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects at most 6 arguments, %d given', $function, $argc)
            );
        }

        $timestamp = VmMath::parseIntBuiltinArgForFrame($frame, 0, $function, 1, 'timestamp');
        $returnFormat = VmDate::SUNFUNCS_RET_STRING;
        $latitude = null;
        $longitude = null;
        $zenith = null;
        $gmtOffset = null;

        if ($argc >= 2) {
            $returnFormat = VmMath::parseIntBuiltinArg($frame->calledArgs[1], $function, 2, 'returnFormat');
        }
        if ($argc >= 3) {
            $latitude = VmMath::parseDoubleBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                $function,
                3,
                'latitude'
            );
        }
        if ($argc >= 4) {
            $longitude = VmMath::parseDoubleBuiltinArg(
                $frame->calledArgs[3]->resolveIndirect(),
                $function,
                4,
                'longitude'
            );
        }
        if ($argc >= 5) {
            $zenith = VmMath::parseDoubleBuiltinArg(
                $frame->calledArgs[4]->resolveIndirect(),
                $function,
                5,
                'zenith'
            );
        }
        if ($argc >= 6) {
            $gmtOffset = VmMath::parseDoubleBuiltinArg(
                $frame->calledArgs[5]->resolveIndirect(),
                $function,
                6,
                'gmt_offset'
            );
        }

        return [
            'timestamp' => $timestamp,
            'returnFormat' => $returnFormat,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'zenith' => $zenith,
            'gmtOffset' => $gmtOffset,
            'argc' => $argc,
        ];
    }
}
