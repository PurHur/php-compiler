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
use PHPCompiler\JIT\Builtin\StringStrContains;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_starts_with() for two strings (subset of PHP 8).
 */
final class str_starts_with extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_starts_with', 2);
        $haystackStr = self::vmStringArg($frame, 0, 'haystack');
        $needleStr = self::vmStringArg($frame, 1, 'needle');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmString::startsWith($haystackStr, $needleStr))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'str_starts_with', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $hay = self::jitStringArg($context, $args[0], 0, 'haystack');
        $needle = self::jitStringArg($context, $args[1], 1, 'needle');

        return StringStrContains::invokeStartsWith($context, $hay, $needle);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'str_starts_with', $paramName)->toString();
        }

        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19273, string.c).
        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'str_starts_with',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'str_starts_with',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'str_starts_with',
            $argIndex,
            $paramName
        );
    }
}
