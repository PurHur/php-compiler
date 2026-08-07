<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPCompiler\ext\pdo\VmPDO;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PDO::getAvailableDrivers() — thin AOT (#27619).
 *
 * php-src: ext/pdo/pdo.c — zim_PDO_getAvailableDrivers
 * Shares the driver list with {@see \PHPCompiler\ext\pdo\pdo_drivers}.
 * Avoids ExternalMethod silent NULL (#579).
 */
final class PdoGetAvailableDrivers implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
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
