<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ObGzhandler;
use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_start() (issue #118, #1056, #8818, #30121, #30508, #31228). */
final class JitObStart
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        ObOutputRuntime::ensureObStackLinked($context);

        $argc = \count($args);
        // php-src stub arity — excess argc is ArgumentCountError, not LogicException (#30508).
        if ($argc > 3) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('ob_start() expects at most 3 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'ob_start_argc_cont');

            return $context->constantFromBool(false);
        }

        // Z_PARAM_LONG $chunk_size / $flags — type-check before start (output.c; #31228).
        // Chunked flush / flag bits are not lowered yet; values are ignored after coercion.
        if ($argc >= 2) {
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitSleep::zParamLong($context, $args[1], 'ob_start', 2, 'chunk_size');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'ob_start_null_chunk_size_te_cont');

                return $context->constantFromBool(false);
            }
            JitSleep::zParamLong($context, $args[1], 'ob_start', 2, 'chunk_size');
        }
        if ($argc >= 3) {
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false))) {
                JitSleep::zParamLong($context, $args[2], 'ob_start', 3, 'flags');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'ob_start_null_flags_te_cont');

                return $context->constantFromBool(false);
            }
            JitSleep::zParamLong($context, $args[2], 'ob_start', 3, 'flags');
        }

        if ($argc >= 1) {
            $callback = $args[0];
            // php-src `?callable $callback = null` — null is equivalent to omitted (#30121).
            if (JITVariable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
                return self::startPlain($context);
            }
            $literal = JitStringArg::compileTimeLiteral($callback);
            if ('ob_gzhandler' === $literal) {
                ObGzhandler::ensureLinked($context);
                $context->builder->call($context->lookupFunction('__phpc_ob_start_with_gzhandler'));

                return $context->constantFromBool(true);
            }
            throw new \LogicException(
                'ob_start() callback "'.$literal.'" not supported in this compiler build; only ob_gzhandler is implemented for JIT'
            );
        }

        return self::startPlain($context);
    }

    private static function startPlain(Context $context): Value
    {
        $context->builder->call($context->lookupFunction('__phpc_ob_start'));

        return $context->constantFromBool(true);
    }
}
