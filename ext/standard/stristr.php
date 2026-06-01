<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** stristr() for two strings (subset of PHP; LLVM via libc strcasestr + slice). */
final class stristr extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('stristr() requires two or three arguments in this compiler build');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $haystackStr = VmString::coerceOperand($haystack);
        $needleStr = VmString::coerceOperand($needle);
        $beforeNeedle = false;
        if (3 === $argc) {
            $flag = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException('stristr() before_needle must be a boolean in this compiler build');
            }
            $beforeNeedle = $flag->toBool();
        }
        $result = VmString::stristr($haystackStr, $needleStr, $beforeNeedle);
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
            throw new \LogicException('stristr() requires two or three arguments in this compiler build');
        }
        $before = null;
        if (3 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[2]->type) {
                throw new \LogicException('stristr() before_needle must be a boolean in this compiler build');
            }
            $before = $context->helper->loadValue($args[2]);
        }

        return JitStrstr::find(
            $context,
            $this->jitString($context, $args[0], 'stristr() argument #1'),
            $this->jitString($context, $args[1], 'stristr() argument #2'),
            $before,
            true
        );
    }
}
