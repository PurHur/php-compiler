<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * JIT lowering for user magic methods — thin trampoline to {@see MagicMethodLlvm}
 * + {@see \PHPCompiler\VM\MagicMethodJitHelper} (#10201).
 */
final class MagicMethodDispatch
{
    public static function hasInstanceMethod(Object_ $object, int $classId, string $methodLc): bool
    {
        return MagicMethodLlvm::hasInstanceMethod($object, $classId, $methodLc);
    }

    public static function resolveInstanceMethodProxy(
        Context $context,
        string $classLc,
        string $methodLc
    ): ?string {
        return MagicMethodLlvm::resolveInstanceMethodProxy($context, $classLc, $methodLc);
    }

    public static function propertyReadUsesMagicGetAtCompileTime(
        Context $context,
        int $classId,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): bool {
        return MagicMethodLlvm::propertyReadUsesMagicGetAtCompileTime(
            $context,
            $classId,
            $declaringClass,
            $propertyName,
            $enclosingBlock
        );
    }

    public static function emitMagicGetIndirectModifyError(Context $context, string $className, string $propertyName): void
    {
        MagicMethodLlvm::emitMagicGetIndirectModifyError($context, $className, $propertyName);
    }

    public static function tryEmitMagicGet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        return MagicMethodLlvm::tryEmitMagicGet($context, $receiver, $declaringClass, $propertyName, $enclosingBlock);
    }

    public static function tryEmitMagicGetDynamic(
        Context $context,
        Value $receiver,
        string $declaringClass,
        Value $nameStr,
        ?Block $enclosingBlock
    ): ?Value {
        return MagicMethodLlvm::tryEmitMagicGetDynamic($context, $receiver, $declaringClass, $nameStr, $enclosingBlock);
    }

    public static function tryEmitMagicSet(
        Context $context,
        Variable $receiver,
        string $propertyName,
        Variable $value,
        ?Block $enclosingBlock
    ): bool {
        return MagicMethodLlvm::tryEmitMagicSet($context, $receiver, $propertyName, $value, $enclosingBlock);
    }

    public static function tryInitMagicCall(
        Context $context,
        string $declaringClassLc,
        string $methodName,
        Variable $receiverVar
    ): bool {
        return MagicMethodLlvm::tryInitMagicCall($context, $declaringClassLc, $methodName, $receiverVar);
    }

    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}|null
     */
    public static function rewriteOutgoingMagicCallArgs(
        Context $context,
        string $methodName,
        array $argEntries,
        array $argOperands
    ): ?array {
        return MagicMethodLlvm::rewriteOutgoingMagicCallArgs($context, $methodName, $argEntries, $argOperands);
    }

    public static function coerceObjectToString(Context $context, Variable $objectVar, ?string $className = null): ?Variable
    {
        return MagicMethodLlvm::coerceObjectToString($context, $objectVar, $className);
    }
}
