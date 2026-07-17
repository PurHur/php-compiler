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

/** sodium_crypto_generichash_update() — streaming BLAKE2b update (php-src ext/sodium/libsodium.c; #20062). */
final class sodium_crypto_generichash_update extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_generichash_update');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $stateArg = $frame->calledArgs[0];
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'message');
        $state = VmSodiumSecretstream::readState($stateArg, $this->getName());
        $ok = VmSodium::generichashUpdate($state, $message);
        VmSodiumSecretstream::writeState($stateArg, $state);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
