<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** rawurlencode() for strings (subset of PHP; VM only). */
final class rawurlencode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('rawurlencode() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('rawurlencode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::rawurlencode($v->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('rawurlencode() is not implemented for JIT in this compiler build');
    }
}
