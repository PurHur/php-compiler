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
 * Shared VM wiring for sodium_crypto_pwhash() (php-src ext/sodium/libsodium.c; #20048).
 */
abstract class SodiumPwhashFunction extends Internal
{
    abstract protected function invoke(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit,
        int $algo
    ): string;

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, $this->getName(), 5, 6);
        $length = VmMath::parseIntBuiltinArgForFrame($frame, 0, $this->getName(), 1, 'length');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'salt');
        $opslimit = VmMath::parseIntBuiltinArgForFrame($frame, 3, $this->getName(), 4, 'opslimit');
        $memlimit = VmMath::parseIntBuiltinArgForFrame($frame, 4, $this->getName(), 5, 'memlimit');
        $algo = VmSodium::CRYPTO_PWHASH_ALG_DEFAULT;
        if (\count($frame->calledArgs) >= 6) {
            $algo = VmMath::parseIntBuiltinArgForFrame($frame, 5, $this->getName(), 6, 'algo');
        }
        $result = $this->invoke($length, $password, $salt, $opslimit, $memlimit, $algo);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
