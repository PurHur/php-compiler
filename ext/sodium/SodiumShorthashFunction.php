<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for sodium_crypto_shorthash() (php-src ext/sodium/libsodium.c; #20063).
 */
abstract class SodiumShorthashFunction extends Internal
{
    abstract protected function invoke(string $message, string $key): string;

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'message');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'key');
        $result = $this->invoke($message, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
