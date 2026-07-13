<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Shared VM wiring for gmp builtins (php-src ext/gmp/gmp.c; issue #3341). */
abstract class GmpFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        $result = $this->compute($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $this->writeReturn($frame, $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not supported for JIT/AOT in this compiler build (issue #3341)');
    }

    /** @return mixed */
    abstract protected function compute(Frame $frame);

    /** @param mixed $result */
    protected function writeReturn(Frame $frame, $result): void
    {
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        if ($result instanceof \PHPCompiler\VM\Variable) {
            $frame->returnVar->copyFrom($result);

            return;
        }
        throw new \LogicException('unsupported gmp return type in '.$this->getName().'()');
    }
}
