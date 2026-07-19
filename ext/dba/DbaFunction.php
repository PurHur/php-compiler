<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for dba_* builtins (php-src ext/dba/dba.c; #4422).
 */
abstract class DbaFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->getName().'() is not implemented for JIT in this compiler build (issue #4422)'
        );
    }
}
