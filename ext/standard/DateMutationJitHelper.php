<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * date_add/date_sub/date_modify/date_diff scalar math for JIT/AOT (#8770, php-in-PHP).
 *
 * VM SSOT: {@see VmDateTimeNative}. Replaces hand-rolled LLVM in {@see DateMutationRuntime}.
 * php-src: ext/date/php_date.c
 */
final class DateMutationJitHelper
{
    private static int $applyTimestamp = 0;

    private static int $applyMicrosecond = 0;

    private static int $diffY = 0;

    private static int $diffM = 0;

    private static int $diffD = 0;

    private static int $diffH = 0;

    private static int $diffI = 0;

    private static int $diffS = 0;

    private static int $diffInvert = 0;

    private static int $diffDays = 0;

    /**
     * unit: 0=second, 1=minute, 2=hour, 3=day, 4=week, 5=month, 6=year
     */
    public static function modifyDelta(int $timestamp, int $amount, int $unitCode, string $tzName): int
    {
        return VmDateTimeNative::modifyRelative(
            $timestamp,
            self::formatModifyLiteral($amount, $unitCode),
            $tzName
        );
    }

    public static function computeApplyInterval(
        int $timestamp,
        int $microsecond,
        int $y,
        int $m,
        int $d,
        int $h,
        int $i,
        int $s,
        float $f,
        int $invert,
        bool $add,
        string $tzName
    ): void {
        $result = VmDateTimeNative::applyIntervalState(
            $timestamp,
            $microsecond,
            [
                'y' => $y,
                'm' => $m,
                'd' => $d,
                'h' => $h,
                'i' => $i,
                's' => $s,
                'f' => $f,
                'invert' => $invert,
            ],
            $tzName,
            $add
        );
        self::$applyTimestamp = $result['timestamp'];
        self::$applyMicrosecond = $result['microsecond'];
    }

    public static function applyOutTimestamp(): int
    {
        return self::$applyTimestamp;
    }

    public static function applyOutMicrosecond(): int
    {
        return self::$applyMicrosecond;
    }

    public static function computeDiffScalars(
        int $baseTs,
        int $targetTs,
        bool $absolute,
        string $tzName
    ): void {
        $diff = VmDateTimeNative::diffTimestamps($baseTs, $targetTs, $tzName, $absolute);
        self::$diffY = $diff['y'];
        self::$diffM = $diff['m'];
        self::$diffD = $diff['d'];
        self::$diffH = $diff['h'];
        self::$diffI = $diff['i'];
        self::$diffS = $diff['s'];
        self::$diffInvert = $diff['invert'];
        self::$diffDays = $diff['days'];
    }

    public static function diffOutY(): int
    {
        return self::$diffY;
    }

    public static function diffOutM(): int
    {
        return self::$diffM;
    }

    public static function diffOutD(): int
    {
        return self::$diffD;
    }

    public static function diffOutH(): int
    {
        return self::$diffH;
    }

    public static function diffOutI(): int
    {
        return self::$diffI;
    }

    public static function diffOutS(): int
    {
        return self::$diffS;
    }

    public static function diffOutInvert(): int
    {
        return self::$diffInvert;
    }

    public static function diffOutDays(): int
    {
        return self::$diffDays;
    }

    private static function formatModifyLiteral(int $amount, int $unitCode): string
    {
        $units = ['second', 'minute', 'hour', 'day', 'week', 'month', 'year'];
        if ($unitCode < 0 || $unitCode >= \count($units)) {
            throw new \LogicException('date_modify(): Failed to parse modifier');
        }
        if (0 === $amount) {
            throw new \LogicException('date_modify(): Failed to parse modifier');
        }
        $sign = $amount < 0 ? '-' : '+';
        $abs = \abs($amount);
        $unit = $units[$unitCode];
        $plural = 1 === $abs ? $unit : $unit.'s';

        return $sign.' '.$abs.' '.$plural;
    }
}
