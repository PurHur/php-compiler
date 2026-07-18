<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strstr() for two strings (subset of PHP; LLVM via libc strstr + slice). */
final class strstr extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strstr() requires two or three arguments in this compiler build');
        }
        $haystackStr = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[0], 'strstr', 0, 'haystack');
        $needleStr = VmString::coerceZparamStrBuiltinArg($frame->calledArgs[1], 'strstr', 1, 'needle');
        $beforeNeedle = false;
        if (3 === $argc) {
            $flag = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException('strstr() before_needle must be a boolean in this compiler build');
            }
            $beforeNeedle = $flag->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmString::strstr($haystackStr, $needleStr, $beforeNeedle);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strstr() requires two or three arguments in this compiler build');
        }
        $before = null;
        if (3 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[2]->type) {
                throw new \LogicException('strstr() before_needle must be a boolean in this compiler build');
            }
            $before = $context->helper->loadValue($args[2]);
        }

        return JitStrstr::find(
            $context,
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'strstr', 0, 'haystack'),
            JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'strstr', 1, 'needle'),
            $before
        );
    }
}
