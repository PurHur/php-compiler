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
 * strcspn() — length of initial segment not matching a character mask (LLVM via libc).
 */
final class strcspn extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('strcspn() requires exactly two arguments in this compiler build');
        }
        $str = $frame->calledArgs[0]->resolveIndirect();
        $mask = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $str->type || Variable::TYPE_STRING !== $mask->type) {
            throw new \LogicException('strcspn() requires two strings in this compiler build');
        }
        $frame->returnVar->int(VmString::strcspn($str->toString(), $mask->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('strcspn() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('strcspn() requires two strings in this compiler build');
        }
        $p0 = $this->stringDataPtr($context, $context->helper->loadValue($args[0]));
        $p1 = $this->stringDataPtr($context, $context->helper->loadValue($args[1]));
        $raw = $context->builder->call($context->lookupFunction('strcspn'), $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($raw, $i64);
    }
}
