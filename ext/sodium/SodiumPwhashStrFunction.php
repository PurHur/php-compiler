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
 * Shared VM wiring for sodium_crypto_pwhash_str() (php-src ext/sodium/libsodium.c; #20048).
 */
abstract class SodiumPwhashStrFunction extends Internal
{
    abstract protected function invoke(string $password, int $opslimit, int $memlimit): string;

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 3 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'password');
        $opslimit = VmMath::parseIntBuiltinArgForFrame($frame, 1, $this->getName(), 2, 'opslimit');
        $memlimit = VmMath::parseIntBuiltinArgForFrame($frame, 2, $this->getName(), 3, 'memlimit');
        $result = $this->invoke($password, $opslimit, $memlimit);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
