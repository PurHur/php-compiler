<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for sodium_crypto_stream*(int $length, …) (php-src ext/sodium/libsodium.c; #15464).
 */
abstract class SodiumStreamLengthFunction extends Internal
{
    abstract protected function invoke(int $length, string $nonce, string $key): string;

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 3 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $length = VmMath::parseIntBuiltinArgForFrame($frame, 0, $this->getName(), 1, 'length');
        $nonce = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'nonce');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'key');
        $result = $this->invoke($length, $nonce, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
