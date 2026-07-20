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

/** strchr() is a PHP alias of strstr() (not libc strchr). */
final class strchr extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strchr() requires two or three arguments in this compiler build');
        }
        $haystackStr = VmString::coerceTrimFamilyStringArg($frame->calledArgs[0], 'strchr', 0, 'haystack');
        $needleStr = VmString::coerceTrimFamilyStringArg($frame->calledArgs[1], 'strchr', 1, 'needle');
        $beforeNeedle = false;
        if (3 === $argc) {
            $flag = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException('strchr() before_needle must be a boolean in this compiler build');
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
            throw new \LogicException('strchr() requires two or three arguments in this compiler build');
        }
        $before = null;
        if (3 === $argc) {
            $before = $this->jitBool($context, $args[2], 'strchr() before_needle');
        }

        return JitStrstr::find(
            $context,
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strchr', 0, 'haystack'),
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'strchr', 1, 'needle'),
            $before
        );
    }
}
