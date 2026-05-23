<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** str_shuffle() — Fisher–Yates shuffle (VM; JIT/AOT via __compiler_str_shuffle). */
final class str_shuffle extends Internal
{
    public function __construct()
    {
        parent::__construct('str_shuffle');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_shuffle() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('str_shuffle() requires a string in this compiler build');
        }
        $frame->returnVar->string(VmString::strShuffle($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('str_shuffle() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('str_shuffle() requires a string in this compiler build');
        }

        return JitStrShuffle::invoke($context, $context->helper->loadValue($args[0]));
    }
}
