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

/**
 * Shared VM wiring for sodium_crypto_box_keypair_from_secretkey_and_publickey() (#20026).
 *
 * php-src: ext/sodium/libsodium.c
 */
abstract class SodiumBoxKeypairFromFunction extends Internal
{
    abstract protected function invoke(string $secretkey, string $publickey): string;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $secretkey = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'secret_key');
        $publickey = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'public_key');
        $result = $this->invoke($secretkey, $publickey);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
