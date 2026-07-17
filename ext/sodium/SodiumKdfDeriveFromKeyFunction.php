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
 * Shared VM wiring for sodium_crypto_kdf_derive_from_key() (php-src ext/sodium/libsodium.c; #20063).
 */
abstract class SodiumKdfDeriveFromKeyFunction extends Internal
{
    abstract protected function invoke(int $subkeyLength, int $subkeyId, string $context, string $key): string;

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 4 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $subkeyLength = VmMath::parseIntBuiltinArgForFrame($frame, 0, $this->getName(), 1, 'subkey_length');
        $subkeyId = VmMath::parseIntBuiltinArgForFrame($frame, 1, $this->getName(), 2, 'subkey_id');
        $context = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'context');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[3], $this->getName(), 3, 'key');
        $result = $this->invoke($subkeyLength, $subkeyId, $context, $key);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
