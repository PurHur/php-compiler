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
 * Shared VM wiring for sodium_crypto_box_publickey_from_secretkey() (#20026).
 *
 * php-src: ext/sodium/libsodium.c
 */
abstract class SodiumBoxPublickeyFromSecretkeyFunction extends Internal
{
    abstract protected function invoke(string $secretkey): string;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $secretkey = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'secret_key');
        $result = $this->invoke($secretkey);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
