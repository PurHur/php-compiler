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

/** sodium_crypto_generichash_init() — streaming BLAKE2b init (php-src ext/sodium/libsodium.c; #20062). */
final class sodium_crypto_generichash_init extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_generichash_init');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, $this->getName(), 0, 2);
        $key = '';
        if (\count($frame->calledArgs) >= 1) {
            $key = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'key');
        }
        $length = VmSodium::CRYPTO_GENERICHASH_BYTES;
        if (\count($frame->calledArgs) >= 2) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, $this->getName(), 2, 'length');
        }
        $state = VmSodium::generichashInit($key, $length);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($state): void {
            $ret->string($state);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
