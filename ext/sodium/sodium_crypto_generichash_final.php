<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_crypto_generichash_final() — streaming BLAKE2b final (php-src ext/sodium/libsodium.c; #20062). */
final class sodium_crypto_generichash_final extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_generichash_final');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, $this->getName(), 1, 2);
        $stateArg = $frame->calledArgs[0];
        $length = VmSodium::CRYPTO_GENERICHASH_BYTES;
        if (\count($frame->calledArgs) >= 2) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, $this->getName(), 2, 'length');
        }
        $state = VmSodiumSecretstream::readState($stateArg, $this->getName());
        $hash = VmSodium::generichashFinal($state, $length);
        VmSodiumSecretstream::writeState($stateArg, $state);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($hash): void {
            $ret->string($hash);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
