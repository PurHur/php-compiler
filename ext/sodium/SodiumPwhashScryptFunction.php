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
 * Shared VM wiring for sodium_crypto_pwhash_scryptsalsa208sha256() (php-src ext/sodium/libsodium.c; #21460).
 */
abstract class SodiumPwhashScryptFunction extends Internal
{
    abstract protected function invoke(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit
    ): string;

    public function execute(Frame $frame): void
    {
        if (5 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 5 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $length = VmMath::parseIntBuiltinArgForFrame($frame, 0, $this->getName(), 1, 'length');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'salt');
        $opslimit = VmMath::parseIntBuiltinArgForFrame($frame, 3, $this->getName(), 4, 'opslimit');
        $memlimit = VmMath::parseIntBuiltinArgForFrame($frame, 4, $this->getName(), 5, 'memlimit');
        $result = $this->invoke($length, $password, $salt, $opslimit, $memlimit);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
