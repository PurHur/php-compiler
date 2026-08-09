<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** VM-only procedural rar builtins (PECL rar; #27878). */
abstract class RarProceduralFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not lowered for JIT/AOT in this compiler build (#27878)');
    }
}
