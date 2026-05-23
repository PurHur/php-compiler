<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rawurldecode() for strings (subset of PHP; JIT/AOT via __string__rawurldecode). */
final class rawurldecode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rawurldecode() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('rawurldecode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::rawurldecode($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('rawurldecode() requires exactly one argument');
        }

        return JitUrlencode::rawurldecode($context, $this->jitString($context, $args[0], 'rawurldecode() argument #1'));
    }

}
