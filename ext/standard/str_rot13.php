<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** str_rot13() — ASCII ROT13 (subset of PHP; native LLVM in JIT/AOT). */
final class str_rot13 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_rot13() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('str_rot13() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::strRot13($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('str_rot13() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('str_rot13() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($args[0]);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        JitStrRot13::transform($context, $copy);

        return $copy;
    }
}
