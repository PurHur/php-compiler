<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * clock_gettime() VM helpers (PHP 8.3; issue #11624).
 *
 * php-src: ext/standard/hrtime.c — PHP_FUNCTION(clock_gettime)
 */
final class VmClockGettime
{
    public static function resolveClockId(Variable $var, string $fn): int
    {
        $var = $var->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($clock) must be of type ClockInterface, %s given',
                $fn,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isClockInterfaceEnum($enumClass->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($clock) must be of type ClockInterface, %s given',
                $fn,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('ClockInterface case missing backing value');
        }

        return self::clockIdFromBacking($entry->backingValue->resolveIndirect()->toInt());
    }

    public static function clockIdFromBacking(int $backing): int
    {
        return match ($backing) {
            VmHrtimeNative::CLOCK_REALTIME, VmHrtimeNative::CLOCK_MONOTONIC => $backing,
            default => throw new \ValueError('Invalid ClockInterface enum value '.$backing),
        };
    }

    public static function clockGettime(int $clockId): HashTable|false
    {
        $pair = VmHrtimeNative::readClock($clockId);
        if (null === $pair) {
            return false;
        }

        return self::buildResult($pair[0], $pair[1]);
    }

    public static function buildResult(int $sec, int $nsec): HashTable
    {
        $ht = new HashTable();
        $secVar = new Variable();
        $secVar->int($sec);
        $ht->add('sec', $secVar);
        $nsecVar = new Variable();
        $nsecVar->int($nsec);
        $ht->add('nsec', $nsecVar);

        return $ht;
    }

    private static function isClockInterfaceEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'ClockInterface');
    }
}
