<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PDO::__construct() — thin AOT driver honesty (#27619).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\pdo} (#36204). php-src: ext/pdo/pdo_dbh.c — zim_PDO___construct / pdo_find_driver
 */
final class PdoConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requirePdo()->construct($context, ...$args);
    }
}
