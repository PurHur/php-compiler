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
 * Shared VM wiring for unary sodium string→bool APIs (php-src ext/sodium/libsodium.c; #20084).
 */
abstract class SodiumOneStringBoolFunction extends Internal
{
    abstract protected function argName(): string;

    abstract protected function invoke(string $value): bool;

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 1);
        $value = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, $this->argName());
        $result = $this->invoke($value);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->bool($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
