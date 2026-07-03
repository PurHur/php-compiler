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

/** Shared VM wiring for sodium_crypto_sign_open() (php-src ext/sodium/libsodium.c; #15541). */
abstract class SodiumSignOpenFunction extends Internal
{
    /**
     * @return string|false
     */
    abstract protected function invoke(string $signedMessage, string $publickey): string|false;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $signedMessage = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'signed_message');
        $publickey = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'public_key');
        $result = $this->invoke($signedMessage, $publickey);
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
