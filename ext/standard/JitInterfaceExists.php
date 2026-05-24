<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for interface_exists() (issue #1371). */
final class JitInterfaceExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        return JitClassExists::invoke($context, $nameArg);
    }
}

