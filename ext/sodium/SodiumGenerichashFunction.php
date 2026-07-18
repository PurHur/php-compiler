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
 * Shared VM wiring for sodium_crypto_generichash() (php-src ext/sodium/libsodium.c; #15530, #20696).
 */
abstract class SodiumGenerichashFunction extends Internal
{
    abstract protected function invoke(string $message, string $key, int $length): string;

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, $this->getName(), 1, 3);
        // Z_PARAM_STR $message / $key — null TypeError on 8.4 forward profile (#20696, ext/sodium/libsodium.c).
        $message = VmString::zparamStrBuiltinArgForFrame($frame, 0, $this->getName(), 0, 'message');
        $key = '';
        if (\count($frame->calledArgs) >= 2) {
            $key = VmString::zparamStrBuiltinArgForFrame($frame, 1, $this->getName(), 1, 'key');
        }
        $length = VmSodium::CRYPTO_GENERICHASH_BYTES;
        if (\count($frame->calledArgs) >= 3) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 2, $this->getName(), 3, 'length');
        }
        $result = $this->invoke($message, $key, $length);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
