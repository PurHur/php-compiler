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
