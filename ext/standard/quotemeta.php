<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringQuotemeta;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * quotemeta() — escape regex metacharacters (subset of PHP).
 *
 * VM: {@see VmString::quotemeta()}; JIT/AOT: {@see StringQuotemeta} + {@see QuotemetaJitHelper}.
 */
final class quotemeta extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('quotemeta() requires exactly one argument in this compiler build');
        }
        $str = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::quotemeta($str));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('quotemeta() requires exactly one argument in this compiler build');
        }
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        StringQuotemeta::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__quotemeta'),
            $str
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'quotemeta', $paramName)->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'quotemeta',
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
                'quotemeta',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'quotemeta',
            $argIndex,
            $paramName
        );
    }
}
