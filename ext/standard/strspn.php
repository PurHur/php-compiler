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
 * strspn() — length of initial segment matching a character mask (LLVM via libc).
 */
final class strspn extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strspn() requires exactly two arguments in this compiler build');
        }
        $str = $frame->calledArgs[0]->resolveIndirect();
        $mask = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $str->type || Variable::TYPE_STRING !== $mask->type) {
            throw new \LogicException('strspn() requires two strings in this compiler build');
        }
        $frame->returnVar->int(VmString::strspn($str->toString(), $mask->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('strspn() requires exactly two arguments in this compiler build');
        }
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'strspn() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'strspn() argument #2'));
        $raw = $context->builder->call($context->lookupFunction('strspn'), $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($raw, $i64);
    }
}
