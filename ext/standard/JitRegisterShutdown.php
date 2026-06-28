<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for register_shutdown_function() (issue #3120). */
final class JitRegisterShutdown
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                'register_shutdown_function() expects at least 1 argument, 0 given'
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        $call = self::resolveCallback($context, $args[0]);
        if (null === $call) {
            throw new \LogicException(
                'register_shutdown_function() callback must be a compile-time function name or closure in this compiler build'
            );
        }
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            $literal = JitStringArg::compileTimeLiteral($args[0]);
            if (null === $literal || '' === $literal) {
                throw new \LogicException(
                    'register_shutdown_function() JIT requires a compile-time function name string in this compiler build; use bin/vm.php for closures'
                );
            }
        }
        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($context->shutdownBlock);
        try {
            self::emitShutdownCall($context, $call, ...\array_slice($args, 1));
        } finally {
            if (null !== $saved) {
                $context->builder->positionAtEnd($saved);
            }
        }
        $context->builder->call($context->lookupFunction('__phpc_shutdown_mark_registered'));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    private static function resolveCallback(Context $context, JITVariable $callback): ?Call
    {
        $literal = JitStringArg::compileTimeLiteral($callback);
        if (null !== $literal && '' !== $literal) {
            $lc = strtolower($literal);
            if (isset($context->functionProxies[$lc])) {
                return $context->functionProxies[$lc];
            }
        }
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return null;
        }
        $call = ClosureHelper::resolveCall($context, $callback);
        if (null === $call && null !== $context->lastClosureCallProxy) {
            $call = $context->lastClosureCallProxy;
        }

        return $call;
    }

    private static function emitShutdownCall(Context $context, Call $call, JITVariable ...$extra): void
    {
        if ($call instanceof Native || $call instanceof ClosureWithCaptures) {
            $native = self::unwrapNative($call);
            $llvmArgs = self::lowerExtraArgs($context, $extra);
            if ($call instanceof ClosureWithCaptures) {
                foreach ($call->captureVariables() as $capture) {
                    $llvmArgs[] = $context->helper->loadValue($capture);
                }
            }
            $context->builder->call($native->function, ...$llvmArgs);

            return;
        }
        $call->call($context, ...$extra);
    }

    private static function unwrapNative(Call $call): Native
    {
        if ($call instanceof Native) {
            return $call;
        }
        if ($call instanceof ClosureWithCaptures) {
            return $call->innerNative();
        }

        throw new \LogicException(
            'register_shutdown_function() callback must be a closure in this compiler build'
        );
    }

    /**
     * @return list<Value>
     */
    private static function lowerExtraArgs(Context $context, array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            $out[] = $context->helper->loadValue($arg);
        }

        return $out;
    }
}
