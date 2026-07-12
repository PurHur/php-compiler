<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for xmlrpc builtins (php-src ext/xmlrpc/xmlrpc.c; #6579).
 */
abstract class XmlrpcFunction extends \PHPCompiler\Func\Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not JIT-lowered in this compiler build');
    }
}
