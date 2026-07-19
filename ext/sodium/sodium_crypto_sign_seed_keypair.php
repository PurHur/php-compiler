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

/** sodium_crypto_sign_seed_keypair() — deterministic Ed25519 keypair from seed (php-src ext/sodium/libsodium.c; #21019). */
final class sodium_crypto_sign_seed_keypair extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_seed_keypair');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $seed = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'seed');
        $result = VmSodium::signSeedKeypair($seed);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
