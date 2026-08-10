<?php

declare(strict_types=1);

/**
 * Runtime late-static class id for AOT / standalone JIT (issue #4792, #10247).
 *
 * php-src: {@see https://github.com/php/php-src/blob/master/Zend/zend_execute.c}
 * SSOT: {@see \PHPCompiler\VM\LateStaticBinding}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\LateStaticBindingRuntime;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCfg\Operand;
use PHPLLVM\Value;

final class LateStaticBindingHelper
{
    public static function useRuntimeLateStatic(Context $context): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType;
    }

    public static function emitStoreClassId(Context $context, Value $classId): void
    {
        LateStaticBindingRuntime::emitStoreClassId($context, $classId);
    }

    public static function emitLoadClassId(Context $context): Value
    {
        return LateStaticBindingRuntime::emitLoadClassId($context);
    }

    /**
     * @return Value int64 class id (0 = use declaring scope fallback)
     */
    public static function emitEffectiveLateStaticClassId(
        Object_ $objectType,
        Block $block
    ): Value {
        return LateStaticBindingRuntime::emitEffectiveLateStaticClassId($objectType, $block);
    }

    /**
     * @return Value {@see __string__*} resolved class name for `static` keyword
     */
    public static function emitLateStaticResolvedNameString(
        Object_ $objectType,
        Block $block,
        string $scopeClass
    ): Value {
        $context = $objectType->jitContext();
        if (!self::useRuntimeLateStatic($context)) {
            $fallback = ClassConstFetchHelper::jitLateStaticClassNameForBlock($objectType, $block) ?? $scopeClass;

            return $context->builder->load($context->constantStringFromString($fallback));
        }
        $classId = self::emitEffectiveLateStaticClassId($objectType, $block);

        return ClassConstFetchHelper::emitClassNameStringFromClassId($objectType, $classId);
    }

    public static function operandNeedsRuntimeClassResolution(Operand $classOp, Context $context): bool
    {
        // Variable classname (`new $class`) needs runtime lookup in EMBED JIT and AOT (#27156).
        if (!$classOp instanceof Operand\Literal) {
            return true;
        }
        // self/static/parent literals only need runtime LSB under standalone/AOT.
        if (!self::useRuntimeLateStatic($context)) {
            return false;
        }
        $lc = strtolower(ltrim($classOp->value, '\\'));

        return \in_array($lc, ['self', 'static', 'parent'], true);
    }

    /**
     * `: static` return TypeError — Class::method(): prefix + expected class name (#29913).
     *
     * Uses compile-time declaring/late-static class name + instanceof (same shape as
     * {@see ClassReturnCheck}) so AOT does not touch the late-static global in the
     * return epilogue (that path segfaults / fails module verify).
     */
    public static function emitAssertStaticReturn(
        Object_ $objectType,
        Block $block,
        Variable $return,
        ?string $callableName = null
    ): void {
        $context = $objectType->jitContext();
        $expectedName = self::compileTimeExpectedStaticReturnName($objectType, $block);
        $prefix = (null !== $callableName && '' !== $callableName) ? "{$callableName}(): " : '';
        if (Variable::TYPE_OBJECT !== $return->type) {
            $scalar = match ($return->type) {
                Variable::TYPE_NATIVE_LONG => 'int',
                Variable::TYPE_NATIVE_DOUBLE => 'float',
                Variable::TYPE_NATIVE_BOOL => 'bool',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_HASHTABLE => 'array',
                default => Variable::getStringType($return->type),
            };
            self::raiseStaticReturnTypeError(
                $context,
                $prefix."Return value must be of type {$expectedName}, {$scalar} returned"
            );

            return;
        }
        $classLc = strtolower(ltrim($expectedName, '\\'));
        $ok = $objectType->emitInstanceOf($return, $classLc);
        $fn = BasicBlockHelper::parentFunction($context);
        $fail = $fn->appendBasicBlock('static_return_type_fail');
        $done = $fn->appendBasicBlock('static_return_type_done');
        $bool = $context->helper->loadValue($ok);
        $context->builder->branchIf($bool, $done, $fail);
        $context->builder->positionAtEnd($fail);
        self::raiseStaticReturnTypeError(
            $context,
            $prefix."Return value must be of type {$expectedName}, object returned"
        );
        $context->builder->positionAtEnd($done);
    }

    private static function compileTimeExpectedStaticReturnName(Object_ $objectType, Block $block): string
    {
        $raw = ClassConstFetchHelper::jitLateStaticClassNameForBlock($objectType, $block);
        if (!is_string($raw) || '' === $raw) {
            if (null !== $block->func && null !== $block->func->class) {
                $className = $block->func->class->value ?? null;
                $raw = is_string($className) ? $className : null;
            }
        }
        if (!is_string($raw) || '' === $raw) {
            return 'static';
        }
        $lc = strtolower(ltrim($raw, '\\'));
        foreach ($objectType->allClassNamesById() as $name) {
            if (strtolower(ltrim((string) $name, '\\')) === $lc) {
                return ltrim((string) $name, '\\');
            }
        }

        return ltrim($raw, '\\');
    }

    private static function raiseStaticReturnTypeError(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            ExceptionBridge::emitTypeErrorAndAbort($context, $message);
            if (null === $context->builder->getInsertBlock()?->getTerminator()) {
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }

            return;
        }
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            TypeErrorRaise::ensureStandaloneBodies($context);
            TryCatchHelper::emitPendTypeErrorForCaller($context, $message);
            TypeErrorRaise::emitRaise($context, $message);
            $fn = $context->builder->getInsertBlock()?->getParent();
            if ($fn instanceof \PHPLLVM\Value\Function_) {
                TryCatchHelper::emitPropagateReturnAfterPendingThrow($context, $fn);
            }

            return;
        }
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }
}
