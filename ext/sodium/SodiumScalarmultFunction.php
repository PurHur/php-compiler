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
 * Shared VM wiring for sodium_crypto_scalarmult() (php-src ext/sodium/libsodium.c; #15516).
 */
abstract class SodiumScalarmultFunction extends Internal
{
    abstract protected function invoke(string $n, string $p): string;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $n = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'n');
        $p = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'p');
        $result = $this->invoke($n, $p);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
