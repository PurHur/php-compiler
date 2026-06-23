<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\MemoryAccounting;
use PHPCompiler\VM\Variable;

/**
 * VM memory introspection without host Zend memory_get_* (issue #4862, #3134).
 *
 * php-src: ext/standard/basic_functions.c, Zend/zend_alloc.c (emalloc subset).
 * JIT/AOT: ext/standard/MemoryJitHelper.php via lib/JIT/Builtin/MemoryRuntime.php.
 */
final class VmMemory
{
    private static int $peakReal = 0;

    public static function resolveUsageArg(Variable $var, string $fn): bool
    {
        $var = $var->resolveIndirect();
        $fromEnum = self::tryMemoryUsageBool($var);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($real_usage) must be of type MemoryUsage|bool, %s given',
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
            '%s(): Argument #1 ($real_usage) must be of type MemoryUsage|bool, %s given',
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
            $usage = self::readRssBytes();
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

    /** php-src: zend_reset_peak_memory_usage — baseline peak at current usage. */
    public static function resetPeakUsage(bool $realUsage = false): void
    {
        if ($realUsage) {
            self::$peakReal = self::readRssBytes();

            return;
        }
        MemoryAccounting::resetPeakToCurrent();
    }

    /**
     * RSS via /proc/self/statm only (VmFsReadNative; #7287, #4862, #8426).
     *
     * JIT/AOT use the same source via MemoryJitHelper (#9377).
     */
    private static function readRssBytes(): int
    {
        if ('Linux' !== \PHP_OS_FAMILY || !is_readable('/proc/self/statm')) {
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
