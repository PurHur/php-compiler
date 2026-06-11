<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** pack() — binary string from format and values (VM via PackEngine; JIT/AOT via __compiler_pack, #5231). */
final class pack extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('pack() requires at least one argument');
        }
        $fmt = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pack', 0, 'format');
        $values = [];
        for ($i = 1; $i < $argc; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $packed = VmPack::pack($fmt, $values, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($packed);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return \call_user_func_array([JitPack::class, 'pack'], array_merge([$context], $args));
    }
}
