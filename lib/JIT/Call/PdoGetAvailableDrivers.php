<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPCompiler\ext\pdo\VmPDO;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PDO::getAvailableDrivers() — thin AOT (#27619, #30994).
 *
 * php-src: ext/pdo/pdo.c — zim_PDO_getAvailableDrivers
 * Shares the driver list with {@see \PHPCompiler\ext\pdo\pdo_drivers}.
 * Avoids ExternalMethod silent NULL (#579).
 * Static — no implicit $this (peer DateTimeZone::listAbbreviations / #30898).
 */
final class PdoGetAvailableDrivers implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'PDO::getAvailableDrivers';

    /** @var list<string> php-src pdo.stub.php — zero-arg static. */
    public array $paramNames = [];

    /** Static method — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError under AOT try/catch (#30994; peer #30898).
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
}
