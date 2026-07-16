<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringCslashes;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stripcslashes() — unescape C-style byte sequences (php-src ext/standard/string.c; issue #3356).
 *
 * Z_PARAM_STR: null TypeError on PHP_COMPILER_PROFILE=8.4 (#19432).
 */
final class stripcslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stripcslashes() requires exactly one argument in this compiler build');
        }
        $subject = self::vmStringArg($frame, 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::stripcslashes($subject))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stripcslashes() requires exactly one argument in this compiler build');
        }
        $subjectLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null !== $subjectLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::stripcslashes($subjectLit))
            );
        }

        StringCslashes::ensureStripcslashes($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_stripcslashes'),
            self::jitStringArg($context, $args[0], 0, 'string')
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'stripcslashes', $paramName)->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'stripcslashes',
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
                'stripcslashes',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'stripcslashes',
            $argIndex,
            $paramName
        );
    }
}
