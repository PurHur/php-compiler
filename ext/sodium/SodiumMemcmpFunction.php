<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for sodium_memcmp() (php-src ext/sodium/libsodium.c; #15531).
 */
abstract class SodiumMemcmpFunction extends Internal
{
    abstract protected function invoke(string $string1, string $string2): int;

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $this->getName(),
                \count($frame->calledArgs)
            ));
        }
        $string1 = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $this->getName(), 0, 'string1');
        $string2 = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->getName(), 1, 'string2');
        $result = $this->invoke($string1, $string2);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects exactly 2 arguments, %d given',
                $this->getName(),
                \count($args)
            ));
        }
        $string1 = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'string1');
        $string2 = JitStringBuiltinArg::lower($context, $args[1], $this->getName(), 1, 'string2');

        return JitSodium::invokeMemcmp($context, $string1, $string2);
    }
}
