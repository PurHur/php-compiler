<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rewinddir() — VM via VmDir; JIT/AOT via __compiler_rewinddir (issue #3235). */
final class rewinddir extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rewinddir() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('rewinddir() handle must be an integer in this compiler build');
        }
        VmDir::rewinddir($handleVar->toInt());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rewinddir() requires exactly one argument in this compiler build');
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__compiler_rewinddir'),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'rewinddir() handle'),
                $i64
            )
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }
}
