<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * bcmath builtins pending libbcmath parity (php-src ext/bcmath/bcmath.c; issue #3365).
 *
 * VM only — arithmetic throws until #3365 lands.
 */
abstract class BcmathStub extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \LogicException($this->pendingMessage());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->pendingMessage());
    }

    private function pendingMessage(): string
    {
        return $this->getName().'() is not implemented in this compiler build (libbcmath parity tracked in issue #3365)';
    }
}
