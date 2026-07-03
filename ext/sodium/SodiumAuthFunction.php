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
 * Shared VM/JIT wiring for sodium_crypto_auth() (php-src ext/sodium/libsodium.c; #15514).
 */
abstract class SodiumAuthFunction extends Internal
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
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $this->getName(),
                \count($args)
            ));
        }
        $message = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'message');
        $key = JitStringBuiltinArg::lower($context, $args[1], $this->getName(), 1, 'key');

        return JitSodium::invokeAuth($context, $message, $key);
    }
}
