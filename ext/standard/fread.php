<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fread() — VM via VmFs; JIT/AOT via __compiler_fread (issue #1117). */
final class fread extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('fread() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $lenVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fread() handle must be an integer in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $lenVar->type) {
            throw new \LogicException('fread() length must be an integer in this compiler build');
        }
        $data = VmFs::fread($handleVar->toInt(), $lenVar->toInt());
        if (false === $data) {
            $frame->returnVar->bool(false);
            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('fread() requires exactly two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(JitLongArg::lower($context, $args[0], 'fread() handle'), $i64);
        $length = $context->builder->truncOrBitCast(JitLongArg::lower($context, $args[1], 'fread() length'), $i64);
        return JitFread::invoke($context, $handle, $length);
    }
}
