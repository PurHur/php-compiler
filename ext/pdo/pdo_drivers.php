<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pdo_drivers() — procedural alias of PDO::getAvailableDrivers (php-src ext/pdo/pdo.c; #20239, #30994).
 */
final class pdo_drivers extends Internal
{
    public function __construct()
    {
        parent::__construct('pdo_drivers');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'pdo_drivers', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmPDO::availableDriversHashTable());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError under AOT try/catch (#30994; peer #30898).
        if (0 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('pdo_drivers() expects exactly 0 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'pdo_drivers_argc_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $ht = VmPDO::availableDriversHashTable();
        $cacheKey = 'pdo_drivers_'
            .(PdoExtensionPolicy::advertisesSqliteDriver() ? 'sqlite' : 'none')
            .'_'
            .(PdoExtensionPolicy::advertisesPgsqlDriver() ? 'pgsql' : 'none');
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $ptr;
    }
}
