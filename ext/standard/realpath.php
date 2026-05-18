<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** realpath() — canonical path when the target exists (VM: VmString; JIT: libc via JitRealpath). */
final class realpath extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('realpath() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('realpath() only supports strings in this compiler build');
        }
        $resolved = VmString::realpath($v->toString());
        if (false === $resolved) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($resolved);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('realpath() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('realpath() only supports strings in this compiler build');
        }

        return JitRealpath::resolve($context, $context->helper->loadValue($args[0]));
    }
}
