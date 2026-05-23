<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_shuffle() — Fisher–Yates byte shuffle (subset of PHP; CSPRNG).
 */
final class str_shuffle extends Internal
{
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
            throw new \LogicException('str_shuffle() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::strShuffle($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('str_shuffle() requires exactly one argument');
        }

        return JitStrShuffle::shuffle($context, $this->jitString($context, $args[0], 'str_shuffle() argument #1'));
    }
}
