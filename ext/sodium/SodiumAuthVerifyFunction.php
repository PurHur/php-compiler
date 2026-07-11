<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for sodium_crypto_auth_verify() (php-src ext/sodium/libsodium.c; #15514).
 */
abstract class SodiumAuthVerifyFunction extends Internal
{
    abstract protected function invoke(string $mac, string $message, string $key): bool;

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 3 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $mac = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'signature');
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'message');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'key');
        $result = $this->invoke($mac, $message, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 3 arguments, %d given',
                $this->getName(),
                \count($args)
            ));
        }
        $mac = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'signature');
        $message = JitStringBuiltinArg::lower($context, $args[1], $this->getName(), 1, 'message');
        $key = JitStringBuiltinArg::lower($context, $args[2], $this->getName(), 2, 'key');

        return JitSodium::invokeAuthVerify($context, $mac, $message, $key);
    }
}
