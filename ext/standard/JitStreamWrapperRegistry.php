<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for stream_wrapper_{register,unregister,restore} (#3383).
 *
 * Registers compile-time string literals into {@see VmStreamWrapperRegistry} during JIT
 * lowering (same pattern as {@see JitConstant}). php-src: ext/standard/streams.c
 */
final class JitStreamWrapperRegistry
{
    public static function register(Context $context, JITVariable $protocol, JITVariable $class): Value
    {
        $protoLit = JitStringArg::compileTimeLiteral($protocol) ?? $protocol->compileTimeString;
        $classLit = JitStringArg::compileTimeLiteral($class) ?? $class->compileTimeString;
        if (null === $protoLit || null === $classLit) {
            throw new \LogicException(
                'stream_wrapper_register() protocol and class must be compile-time string literals in this compiler build (issue #3383)'
            );
        }
        self::requireValidWrapperClassLiteral($context, $classLit);

        return self::materializeBool($context, VmStreamWrapperRegistry::register($protoLit, $classLit));
    }

    public static function unregister(Context $context, JITVariable $protocol): Value
    {
        $protoLit = JitStringArg::compileTimeLiteral($protocol) ?? $protocol->compileTimeString;
        if (null === $protoLit) {
            throw new \LogicException(
                'stream_wrapper_unregister() protocol must be a compile-time string literal in this compiler build (issue #3383)'
            );
        }

        return self::materializeBool($context, VmStreamWrapperRegistry::unregister($protoLit));
    }

    public static function restore(Context $context, JITVariable $protocol): Value
    {
        $protoLit = JitStringArg::compileTimeLiteral($protocol) ?? $protocol->compileTimeString;
        if (null === $protoLit) {
            throw new \LogicException(
                'stream_wrapper_restore() protocol must be a compile-time string literal in this compiler build (issue #3383)'
            );
        }

        return self::materializeBool($context, VmStreamWrapperRegistry::restore($protoLit));
    }

    public static function requireExactArgCount(Context $context, array $args, string $function, int $expected): bool
    {
        $argc = \count($args);
        if ($argc !== $expected) {
            ExceptionBridge::emitArgumentCountError(
                $context,
                \sprintf(
                    '%s() expects exactly %d argument%s, %d given',
                    $function,
                    $expected,
                    1 === $expected ? '' : 's',
                    $argc
                )
            );

            return false;
        }

        return true;
    }

    private static function requireValidWrapperClassLiteral(Context $context, string $className): void
    {
        $exists = ReflectionBuiltinHelper::classExistsLiteral($context, $className);
        $i1 = $context->getTypeFromString('int1');
        $missing = $context->builder->icmp(Builder::INT_EQ, $exists, $i1->constInt(0, false));
        $okBlock = BasicBlockHelper::append($context, 'stream_wrapper_reg_class_ok');
        $errBlock = BasicBlockHelper::append($context, 'stream_wrapper_reg_class_err');
        $context->builder->branchIf($missing, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            \sprintf(
                'stream_wrapper_register(): Argument #2 ($class) must be a valid class name, %s given',
                $className
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    private static function materializeBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool($value));

        return JitValueBox::pointer($context, $slot);
    }
}
