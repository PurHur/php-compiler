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
 * Shared VM wiring for sodium_crypto_pwhash_str_verify() (php-src ext/sodium/libsodium.c; #20048).
 */
abstract class SodiumPwhashStrVerifyFunction extends Internal
{
    abstract protected function invoke(string $hash, string $password): bool;

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $hash = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'hash');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'password');
        $result = $this->invoke($hash, $password);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
