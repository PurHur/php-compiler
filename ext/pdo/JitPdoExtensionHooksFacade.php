<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\PdoExtensionHooks;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pdo surfaces for lib/JIT Call Pdo* (#36204).
 *
 * php-src: ext/pdo/pdo_dbh.c / pdo.c — zim_PDO___construct / zim_PDO_getAvailableDrivers.
 * Registered from {@see Module::jitInit} so Call files do not import ext/pdo.
 */
final class JitPdoExtensionHooksFacade implements PdoExtensionHooks
{
    public function construct(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('PDO::__construct() called without $this');
        }
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'PDO::__construct() expects at least 1 argument, 0 given'
            );
        }

        $dsnLit = JitStringBuiltinArg::compileTimeLiteral($args[1])
            ?? $args[1]->compileTimeString;
        if (null === $dsnLit) {
            if (!self::anyDriverAdvertised()) {
                return self::throwCouldNotFindDriver($context);
            }
            throw new \LogicException(
                'PDO::__construct() requires a compile-time string $dsn under thin AOT in this build (#27619)'
            );
        }

        $driver = VmPDO::dsnDriverPrefix($dsnLit);
        if (!self::driverAdvertised($driver)) {
            return self::throwCouldNotFindDriver($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $context->type->object->lookup('PDO');
        ReflectionSetup::markConstructed($context, $obj);

        return self::voidResult($context);
    }

    public function getAvailableDrivers(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('PDO::getAvailableDrivers() expects exactly 0 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'pdo_getavailabledrivers_argc_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $ht = VmPDO::availableDriversHashTable();
        $cacheKey = 'pdo_getavailabledrivers_'
            .(PdoExtensionPolicy::advertisesSqliteDriver() ? 'sqlite' : 'none')
            .'_'
            .(PdoExtensionPolicy::advertisesPgsqlDriver() ? 'pgsql' : 'none')
            .'_'
            .(PdoExtensionPolicy::advertisesMysqlDriver() ? 'mysql' : 'none');
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $slot;
    }

    private static function anyDriverAdvertised(): bool
    {
        return PdoExtensionPolicy::advertisesSqliteDriver()
            || PdoExtensionPolicy::advertisesPgsqlDriver()
            || PdoExtensionPolicy::advertisesMysqlDriver();
    }

    private static function driverAdvertised(string $driver): bool
    {
        return match ($driver) {
            'sqlite' => PdoExtensionPolicy::advertisesSqliteDriver(),
            'pgsql' => PdoExtensionPolicy::advertisesPgsqlDriver(),
            // php-src advertises pdo_mysql but this build has no native factory yet
            // (VmPDO::connect always throws) — keep AOT fail-closed (#27619 / #3435).
            'mysql' => false,
            default => false,
        };
    }

    private static function throwCouldNotFindDriver(Context $context): Value
    {
        TryCatchHelper::emitCatchableClassError(
            $context,
            'PDOException',
            'could not find driver'
        );
        $unreachable = BasicBlockHelper::append($context, 'pdo_ctor_missing_driver_unreach');
        $context->builder->positionAtEnd($unreachable);

        return self::voidResult($context);
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
