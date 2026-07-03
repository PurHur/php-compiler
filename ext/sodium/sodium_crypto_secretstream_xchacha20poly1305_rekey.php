<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_crypto_secretstream_xchacha20poly1305_rekey() — rotate stream key (php-src ext/sodium/libsodium.c; #15462). */
final class sodium_crypto_secretstream_xchacha20poly1305_rekey extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretstream_xchacha20poly1305_rekey');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $stateArg = $frame->calledArgs[0];
        $state = VmSodiumSecretstream::readState($stateArg, $this->getName());
        VmSodium::secretstreamRekey($state);
        VmSodiumSecretstream::writeState($stateArg, $state);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->null();
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
