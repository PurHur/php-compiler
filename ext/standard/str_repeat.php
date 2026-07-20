<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrRepeat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * str_repeat() for strings (subset of PHP).
 *
 * VM: {@see VmString::repeat()}; JIT/AOT: {@see StringStrRepeat} + {@see StrRepeatJitHelper}.
 */
final class str_repeat extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('str_repeat() requires exactly two arguments');
        }
        $input = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'str_repeat', 0, 'string');
        $times = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'str_repeat', 2, 'times');
        $result = VmString::repeat($input, $times);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== count($args)) {
            throw new \LogicException('str_repeat() requires exactly two arguments');
        }

        StringStrRepeat::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_str_repeat'),
            self::jitStringArg($context, $args[0]),
            JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'str_repeat', 2, 'times', true)
        );
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#20080, ext/standard/string.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'str_repeat', 0, 'string');
        }

        return JitStringBuiltinArg::lowerZparamStr($context, $arg, 'str_repeat', 0, 'string');
    }
}
