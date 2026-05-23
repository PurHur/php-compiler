<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** preg_quote() — escape regex metacharacters (subset of PHP; native LLVM in JIT/AOT). */
final class preg_quote extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('preg_quote() requires one or two arguments in this compiler build');
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('preg_quote() subject must be a string in this compiler build');
        }
        $delimiter = null;
        if (2 === $argc) {
            $delimVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $delimVar->type) {
                throw new \LogicException('preg_quote() delimiter must be a string in this compiler build');
            }
            $delimiter = $delimVar->toString();
        }
        $frame->returnVar->string(VmString::pregQuote($subject->toString(), $delimiter));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('preg_quote() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('preg_quote() subject must be a string in this compiler build');
        }
        $subject = $context->helper->loadValue($args[0]);
        if (1 === $argc) {
            return JitPregQuote::quote($context, $subject, null);
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('preg_quote() delimiter must be a string in this compiler build');
        }

        return JitPregQuote::quote($context, $subject, $context->helper->loadValue($args[1]));
    }
}
