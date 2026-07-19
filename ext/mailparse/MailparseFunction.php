<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for mailparse builtins (PECL mailparse; #6383).
 */
abstract class MailparseFunction extends \PHPCompiler\Func\Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not JIT-lowered in this compiler build (issue #6383)');
    }
}
