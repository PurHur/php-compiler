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

/** Shared VM wiring for sodium_crypto_sign_verify_detached() (php-src ext/sodium/libsodium.c; #15541). */
abstract class SodiumSignVerifyDetachedFunction extends Internal
{
    abstract protected function invoke(string $signature, string $message, string $publickey): bool;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 3);
        $signature = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'signature');
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'message');
        $publickey = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'public_key');
        $result = $this->invoke($signature, $message, $publickey);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
