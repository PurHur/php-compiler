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
 * Shared VM wiring for binary sodium string→string APIs (php-src ext/sodium/libsodium.c; #20084).
 */
abstract class SodiumTwoStringFunction extends Internal
{
    abstract protected function argName0(): string;

    abstract protected function argName1(): string;

    abstract protected function invoke(string $a, string $b): string;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 2);
        $a = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, $this->argName0());
        $b = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, $this->argName1());
        $result = $this->invoke($a, $b);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
