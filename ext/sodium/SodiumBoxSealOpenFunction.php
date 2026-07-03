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
 * Shared VM wiring for sodium_crypto_box_seal_open() (php-src ext/sodium/libsodium.c; #15515).
 */
abstract class SodiumBoxSealOpenFunction extends Internal
{
    /**
     * @return string|false
     */
    abstract protected function invoke(string $ciphertext, string $keypair): string|false;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $ciphertext = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'ciphertext');
        $keypair = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'key_pair');
        $result = $this->invoke($ciphertext, $keypair);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
