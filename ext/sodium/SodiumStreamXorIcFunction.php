<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM wiring for sodium_crypto_stream_xchacha20_xor_ic() (php-src ext/sodium/libsodium.c; #15461).
 */
abstract class SodiumStreamXorIcFunction extends Internal
{
    abstract protected function invoke(string $message, string $nonce, int $counter, string $key): string;

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 4 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'message');
        $nonce = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'nonce');
        $counter = VmMath::parseIntBuiltinArgForFrame($frame, 2, $this->getName(), 3, 'counter');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[3], $this->getName(), 3, 'key');
        $result = $this->invoke($message, $nonce, $counter, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
