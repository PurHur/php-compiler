<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPCompiler\ext\pdo\VmPDO;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PDO::__construct() — thin AOT driver honesty (#27619).
 *
 * php-src: ext/pdo/pdo_dbh.c — zim_PDO___construct / pdo_find_driver
 *
 * When the requested driver is not advertised (host gate / ENABLE), emit a catchable
 * {@see \PDOException} "could not find driver" instead of ExternalMethod no-op success.
 * Advertised sqlite/pgsql open still needs a follow-on native factory under thin AOT;
 * this path fails closed for missing drivers (artifact-honesty).
 */
final class PdoConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
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
            // No compile-time DSN: if nothing is advertised, every DSN fails closed.
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

        // Driver advertised — mark constructed so ENABLE/compliance paths do not look
        // unconstructed. Full native open under thin AOT remains a follow-on (#27619 scope:
        // missing-driver honesty).
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $context->type->object->lookup('PDO');
        ReflectionSetup::markConstructed($context, $obj);

        return self::voidResult($context);
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
