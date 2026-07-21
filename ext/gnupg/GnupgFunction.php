<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for gnupg builtins (PECL gnupg; issue #6668).
 */
abstract class GnupgFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #6668)');
    }
}
