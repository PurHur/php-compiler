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
 * Shared VM wiring for sodium_crypto_box() (php-src ext/sodium/libsodium.c; #20026).
 */
abstract class SodiumBoxFunction extends Internal
{
    abstract protected function invoke(string $message, string $nonce, string $keypair): string;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 3);
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'message');
        $nonce = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'nonce');
        $keypair = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'key_pair');
        $result = $this->invoke($message, $nonce, $keypair);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
