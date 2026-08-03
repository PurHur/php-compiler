<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * pdo_drivers() — procedural alias of PDO::getAvailableDrivers (php-src ext/pdo/pdo.c; #20239).
 */
final class pdo_drivers extends Internal
{
    public function __construct()
    {
        parent::__construct('pdo_drivers');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pdo_drivers() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmPDO::availableDriversHashTable());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pdo_drivers() expects exactly 0 arguments, %d given',
                $argc
            ));
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
