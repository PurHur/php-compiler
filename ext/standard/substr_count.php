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
 * substr_count() for two strings with optional offset and length (subset of PHP; LLVM JIT).
 */
final class substr_count extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('substr_count() requires two to four arguments in this compiler build');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $needle->type) {
            throw new \LogicException('substr_count() only supports strings in this compiler build');
        }
        $offset = 0;
        if ($argc >= 3) {
            $offVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offVar->type) {
                throw new \LogicException('substr_count() offset must be an integer in this compiler build');
            }
            $offset = $offVar->toInt();
        }
        $length = null;
        if (4 === $argc) {
            $lenVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('substr_count() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        $frame->returnVar->int(
            VmString::substr_count($haystack->toString(), $needle->toString(), $offset, $length)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('substr_count() requires two to four arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('substr_count() only supports strings in this compiler build');
        }
        if ($argc >= 3 && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('substr_count() offset must be an integer in this compiler build');
        }
        if (4 === $argc && JITVariable::TYPE_NATIVE_LONG !== $args[3]->type) {
            throw new \LogicException('substr_count() length must be an integer in this compiler build');
        }

        $hay = $context->helper->loadValue($args[0]);
        $needle = $context->helper->loadValue($args[1]);
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? $context->builder->truncOrBitCast($context->helper->loadValue($args[2]), $i64)
            : null;
        $length = 4 === $argc
            ? $context->builder->truncOrBitCast($context->helper->loadValue($args[3]), $i64)
            : null;

        return JitSubstrCount::count($context, $hay, $needle, $offset, $length);
    }
}
