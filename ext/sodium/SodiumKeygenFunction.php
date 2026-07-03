<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for sodium_crypto_*_keygen() (php-src ext/sodium/libsodium.c; #15464).
 */
abstract class SodiumKeygenFunction extends Internal
{
    abstract protected function invoke(): string;

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 0 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $result = $this->invoke();
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
