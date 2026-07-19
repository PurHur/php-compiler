<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for zmq_* builtins (php/pecl-networking-zmq; #6443).
 */
abstract class ZmqFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->getName().'() is not implemented for JIT in this compiler build (issue #6443)'
        );
    }
}
