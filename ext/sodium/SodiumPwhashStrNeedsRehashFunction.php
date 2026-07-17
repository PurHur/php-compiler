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
 * Shared VM wiring for sodium_crypto_pwhash_str_needs_rehash() (php-src ext/sodium/libsodium.c; #20048).
 */
abstract class SodiumPwhashStrNeedsRehashFunction extends Internal
{
    abstract protected function invoke(string $hash, int $opslimit, int $memlimit): bool;

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 3 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        // php-src stub names arg #1 $password but it is the encoded hash string.
        $hash = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'password');
        $opslimit = VmMath::parseIntBuiltinArgForFrame($frame, 1, $this->getName(), 2, 'opslimit');
        $memlimit = VmMath::parseIntBuiltinArgForFrame($frame, 2, $this->getName(), 3, 'memlimit');
        $result = $this->invoke($hash, $opslimit, $memlimit);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
