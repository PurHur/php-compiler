<?php

declare(strict_types=1);

/**
 * Runtime late-static class id for AOT / standalone JIT (issue #4792, Zend get_called_scope).
 *
 * php-src: {@see https://github.com/php/php-src/blob/master/Zend/zend_execute.c}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class LateStaticBindingHelper
{
    private static ?Value $classIdGlobal = null;

    /** @var object|null LLVM module identity — static Value must not leak across Context instances */
    private static $classIdModule = null;

    public static function useRuntimeLateStatic(Context $context): bool
    {
        return Builtin::LOAD_TYPE_STANDALONE === $context->loadType;
    }

    public static function ensureClassIdGlobal(Context $context): Value
    {
        $module = $context->module;
        if (null !== self::$classIdGlobal && self::$classIdModule === $module) {
            return self::$classIdGlobal;
        }

        $existing = $module->getNamedGlobal('phpc_late_static_class_id');
        if (null !== $existing) {
            self::$classIdGlobal = $existing;
            self::$classIdModule = $module;

            return self::$classIdGlobal;
        }

        $i64 = $context->getTypeFromString('int64');
        self::$classIdGlobal = $module->addGlobal($i64, 'phpc_late_static_class_id');
        self::$classIdGlobal->setInitializer($i64->constInt(0, false));
        self::$classIdModule = $module;

        return self::$classIdGlobal;
    }

    public static function emitStoreClassId(Context $context, Value $classId): void
    {
        $context->builder->store($classId, self::ensureClassIdGlobal($context));
    }

    public static function emitLoadClassId(Context $context): Value
    {
        return $context->builder->load(self::ensureClassIdGlobal($context));
    }

    /**
     * @return Value int64 class id (0 = use declaring scope fallback)
     */
    public static function emitEffectiveLateStaticClassId(
        Object_ $objectType,
        Block $block
    ): Value {
        $context = $objectType->jitContext();
        $runtimeId = self::emitLoadClassId($context);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $scopeClass = ClassConstFetchHelper::resolveJitScopeClassNameForBlock($objectType, $block);
        if (null === $scopeClass) {
            return $runtimeId;
        }
        $fallbackId = $objectType->lookup($scopeClass);
        $hasRuntime = $context->builder->icmp(Builder::INT_NE, $runtimeId, $zero);

        return $context->builder->select(
            $hasRuntime,
            $runtimeId,
            $context->constantFromInteger($fallbackId, 'int64')
        );
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
            $fallback = self::jitLateStaticClassName($objectType, $block) ?? $scopeClass;

            return $context->builder->load($context->constantStringFromString($fallback));
        }
        $classId = self::emitEffectiveLateStaticClassId($objectType, $block);

        return ClassConstFetchHelper::emitClassNameStringFromClassId($objectType, $classId);
    }

    public static function operandNeedsRuntimeClassResolution(Operand $classOp, Context $context): bool
    {
        if (!self::useRuntimeLateStatic($context)) {
            return false;
        }
        if (!$classOp instanceof Operand\Literal) {
            return true;
        }
        $lc = strtolower(ltrim($classOp->value, '\\'));

        return \in_array($lc, ['self', 'static', 'parent'], true);
    }

    public static function emitAssertStaticReturn(
        Object_ $objectType,
        Block $block,
        Variable $return
    ): void {
        $context = $objectType->jitContext();
        if (Variable::TYPE_OBJECT !== $return->type) {
            TypeErrorRaise::emitRaise(
                $context,
                'Return value must be of type static, '
                .Variable::getStringType($return->type).' given'
            );

            return;
        }
        $expectedId = self::emitEffectiveLateStaticClassId($objectType, $block);
        $obj = $return->value;
        $objMap = $context->structFieldMap['__object__'];
        $actualId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $ok = $objectType->emitClassIdMatchesLateStaticReturn($actualId, $expectedId);
        $fn = BasicBlockHelper::parentFunction($context);
        $fail = $fn->appendBasicBlock('static_return_type_fail');
        $done = $fn->appendBasicBlock('static_return_type_done');
        $context->builder->branchIf($ok, $done, $fail);
        $context->builder->positionAtEnd($fail);
        TypeErrorRaise::emitRaise($context, 'Return value must be of type static');
        $context->builder->positionAtEnd($done);
    }
}
