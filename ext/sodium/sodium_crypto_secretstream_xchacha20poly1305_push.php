<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_crypto_secretstream_xchacha20poly1305_push() — encrypt stream chunk (php-src ext/sodium/libsodium.c; #15462). */
final class sodium_crypto_secretstream_xchacha20poly1305_push extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretstream_xchacha20poly1305_push');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 2 and 4 arguments, %d given',
                $this->getName(),
                $argc
            ));
        }
        $stateArg = $frame->calledArgs[0];
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'message');
        $additionalData = $argc >= 3
            ? VmString::coerceStringBuiltinArg($frame->calledArgs[2], $this->getName(), 2, 'additional_data')
            : '';
        $tag = VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        if ($argc >= 4) {
            $tagArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $tagArg->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #4 ($tag) must be of type int, %s given',
                    $this->getName(),
                    EnumCaseSupport::typeNameForVariable($tagArg)
                ));
            }
            $tag = $tagArg->toInt();
        }
        $state = VmSodiumSecretstream::readState($stateArg, $this->getName());
        $ciphertext = VmSodium::secretstreamPush($state, $message, $additionalData, $tag);
        VmSodiumSecretstream::writeState($stateArg, $state);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ciphertext): void {
            $ret->string($ciphertext);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
