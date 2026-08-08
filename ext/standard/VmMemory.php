<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\MemoryAccounting;
use PHPCompiler\VM\Variable;

/**
 * VM memory introspection without host Zend memory_get_* (issue #4862, #3134).
 *
 * php-src: ext/standard/basic_functions.c, Zend/zend_alloc.c (emalloc + real_size).
 * JIT/AOT: ext/standard/MemoryJitHelper.php via lib/JIT/Builtin/MemoryRuntime.php.
 *
 * $real_usage=true tracks Zend AG(real_size)/AG(real_peak): a sticky process baseline
 * (RSS once) plus live emalloc delta — not live /proc RSS, which does not shrink on
 * unset() (#26769 / re-#7310).
 */
final class VmMemory
{
    /** Process baseline for real_usage (captured once per request from /proc RSS). */
    private static int $realBase = -1;

    /** MemoryAccounting::currentBytes() when $realBase was captured. */
    private static int $emallocAtRealBase = 0;

    private static int $peakReal = 0;

    /** Reset real counters at request start (peer MemoryAccounting::beginRequest). */
    public static function beginRequest(): void
    {
        self::$realBase = -1;
        self::$emallocAtRealBase = 0;
        self::$peakReal = 0;
    }

    public static function resolveUsageArg(Variable $var, string $fn): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            VmNullNumberParamDeprecation::emit(null, $fn, 1, 'real_usage', 'bool');

            return false;
        }
        $fromEnum = self::tryMemoryUsageBool($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($real_usage) must be of type bool, %s given',
                $fn,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return 0 !== $var->toInt();
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #1 ($real_usage) must be of type bool, %s given',
            $fn,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    public static function tryMemoryUsageBool(Variable $var): ?bool
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isMemoryUsageEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('MemoryUsage case missing backing value');
        }

        return self::memoryUsageBoolFromBacking($entry->backingValue->resolveIndirect()->toInt());
    }

    public static function memoryUsageBoolFromBacking(int $backing): bool
    {
        return match ($backing) {
            0 => false,
            1 => true,
            default => throw new \ValueError('Invalid MemoryUsage enum value '.$backing),
        };
    }

    private static function isMemoryUsageEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'MemoryUsage');
    }

    public static function getUsage(bool $realUsage = false): int
    {
        if ($realUsage) {
            $usage = self::currentRealBytes();
            if ($usage > self::$peakReal) {
                self::$peakReal = $usage;
            }

            return $usage;
        }

        return MemoryAccounting::currentBytes();
    }

    public static function getPeakUsage(bool $realUsage = false): int
    {
        if ($realUsage) {
            self::getUsage(true);

            return self::$peakReal;
        }

        return MemoryAccounting::peakBytes();
    }

    /**
     * php-src: zend_memory_reset_peak_usage — baseline emalloc + real peaks (#26104, #26769).
     *
     * No $real_usage flag: Zend takes zero args and resets both peaks.
     */
    public static function resetPeakUsage(): void
    {
        MemoryAccounting::resetPeakToCurrent();
        self::$peakReal = self::currentRealBytes();
    }

    /**
     * Zend AG(real_size): sticky RSS baseline + live emalloc delta (#26769).
     *
     * Live /proc RSS does not drop when PHP frees heap strings; emalloc does.
     */
    private static function currentRealBytes(): int
    {
        self::ensureRealBase();
        $delta = MemoryAccounting::currentBytes() - self::$emallocAtRealBase;

        return max(self::pageSize(), self::$realBase + $delta);
    }

    private static function ensureRealBase(): void
    {
        if (self::$realBase >= 0) {
            return;
        }
        $rss = self::readRssBytes();
        self::$realBase = $rss > 0 ? $rss : self::pageSize();
        self::$emallocAtRealBase = MemoryAccounting::currentBytes();
        self::$peakReal = self::$realBase;
    }

    /**
     * RSS via /proc/self/statm only (VmFsReadNative; #7287, #4862, #8426).
     *
     * Used once per request as the real_usage baseline — not as the live counter.
     * JIT/AOT share this path via MemoryJitHelper (#9377).
     */
    private static function readRssBytes(): int
    {
        // Do not gate on is_readable() — thin AOT reports false while read works (#18897 / #27238).
        if ('Linux' !== \PHP_OS_FAMILY) {
            return 0;
        }
        $statm = VmFsReadNative::read('/proc/self/statm');
        if (false === $statm || '' === $statm) {
            return 0;
        }
        $parts = preg_split('/\s+/', trim($statm));
        $rssPages = (int) ($parts[1] ?? 0);

        return $rssPages * self::pageSize();
    }

    private static function pageSize(): int
    {
        static $size = null;
        if (null !== $size) {
            return $size;
        }
        $ps = (int) @ini_get('memory.page_size');
        $size = $ps > 0 ? $ps : 4096;

        return $size;
    }
}
