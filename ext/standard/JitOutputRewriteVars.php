<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\RewriteVarsRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypeErrorRaise;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT/AOT helpers for output_add_rewrite_var() / output_reset_rewrite_vars() via OutputRewriteVarsJitHelper PHP (#6031, #9753). */
final class JitOutputRewriteVars
{
    public static function add(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'output_add_rewrite_var() expects exactly 2 arguments, '.\count($args).' given'
            );

            return self::boolTrue($context);
        }
        $name = JitStringBuiltinArg::lower($context, $args[0], 'output_add_rewrite_var', 0, 'name');
        $value = JitStringBuiltinArg::lower($context, $args[1], 'output_add_rewrite_var', 1, 'value');

        return self::boolTrue($context, RewriteVarsRuntime::emitAdd($context, $name, $value));
    }

    public static function reset(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'output_reset_rewrite_vars() expects exactly 0 arguments, '.\count($args).' given'
            );

            return self::boolTrue($context);
        }

        return self::boolTrue($context, RewriteVarsRuntime::emitReset($context));
    }

    private static function boolTrue(Context $context, ?Value $boolVal = null): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $boolVal ?? $context->getTypeFromString('int1')->constInt(1, false)
        );

        return $ptr;
    }
}
