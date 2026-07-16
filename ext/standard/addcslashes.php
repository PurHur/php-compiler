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

/** addcslashes() — C-style selective escaping (php-src ext/standard/string.c; issue #3356). */
final class addcslashes extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('addcslashes() requires exactly two arguments in this compiler build');
        }
        $subject = self::vmStringArg($frame, 0, 'str');
        $charlist = self::vmStringArg($frame, 1, 'characters');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::addcslashes($subject, $charlist))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('addcslashes() requires exactly two arguments in this compiler build');
        }
        $subjectLit = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        $charlistLit = JitStringArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null !== $subjectLit && null !== $charlistLit) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::addcslashes($subjectLit, $charlistLit))
            );
        }

        StringCslashes::ensureLinked($context);
        $subject = self::jitStringArg($context, $args[0], 0, 'str');
        if (null !== $charlistLit) {
            return $context->builder->call(
                $context->lookupFunction('__compiler_addcslashes'),
                $subject,
                $context->builder->load($context->constantStringFromString($charlistLit))
            );
        }
        $charlist = self::jitStringArg($context, $args[1], 1, 'characters');

        return $context->builder->call(
            $context->lookupFunction('__compiler_addcslashes'),
            $subject,
            $charlist
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'addcslashes', $paramName)->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'addcslashes',
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
                'addcslashes',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'addcslashes',
            $argIndex,
            $paramName
        );
    }
}
