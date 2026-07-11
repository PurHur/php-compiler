<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_crypto_secretstream_xchacha20poly1305_pull() — decrypt stream chunk (php-src ext/sodium/libsodium.c; #15462). */
final class sodium_crypto_secretstream_xchacha20poly1305_pull extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretstream_xchacha20poly1305_pull');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 2 and 3 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        $stateArg = $frame->calledArgs[0];
        $ciphertext = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'ciphertext');
        $additionalData = $argc >= 3
            ? VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'additional_data')
            : '';
        $state = VmSodiumSecretstream::readState($stateArg, $this->getName());
        $result = VmSodium::secretstreamPull($state, $ciphertext, $additionalData);
        VmSodiumSecretstream::writeState($stateArg, $state);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->copyFrom(VmJson::import($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
