<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\Builtin\Type\ObjectEnumCasePropertyLlvm;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT UnhandledMatchError object/enum suffix — php-src smart_str_append_zval (#29248).
 *
 * Enums: {@code EnumName::CaseName}; other objects: {@code of type ClassName}.
 * SSOT for scalar/VM path: {@see \PHPCompiler\VM\MatchUnhandledSupport}.
 */
final class MatchUnhandledJitHelper
{
    /**
     * Suffix after "Unhandled match case " for an object or enum-case operand.
     */
    public static function formatObjectOrEnumCaseSuffix(Context $context, Variable $operand): Value
    {
        $objBuiltin = self::objectBuiltin($context);
        $obj = self::loadObjectPtr($context, $operand);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $className = ReflectionBuiltinHelper::classNameStringFromClassId($context, $classId);
        $enumIds = $objBuiltin->registeredEnumClassIds();
        if ([] === $enumIds) {
            return self::ofTypePrefix($context, $className);
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $done = $fn->appendBasicBlock('umatch_enum_msg_done');
        $nonEnum = $fn->appendBasicBlock('umatch_enum_msg_non_enum');
        $dest = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $i64 = $context->getTypeFromString('int64');
        $checkBlock = $entry;
        $lastIdx = \count($enumIds) - 1;
        foreach ($enumIds as $idx => $enumId) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($enumId, false)
            );
            $caseBlock = $fn->appendBasicBlock('umatch_enum_msg_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $nonEnum
                : $fn->appendBasicBlock('umatch_enum_msg_try_'.($idx + 1));
            $context->builder->branchIf($match, $caseBlock, $nextBlock);
            $context->builder->positionAtEnd($caseBlock);
            $caseNameVar = ObjectEnumCasePropertyLlvm::enumCasePropertyFetch(
                $objBuiltin,
                $obj,
                $enumId,
                'name'
            );
            $sep = $context->builder->load($context->constantStringFromString('::'));
            $left = JitStringConcat::concat($context, $className, $sep);
            $full = JitStringConcat::concat(
                $context,
                $left,
                $context->helper->loadValue($caseNameVar)
            );
            $context->builder->store($full, $dest);
            $context->builder->branch($done);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($nonEnum);
        $ofType = self::ofTypePrefix($context, $className);
        $context->builder->store($ofType, $dest);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($dest);
    }

    /**
     * Full "Unhandled match case …" message for object/enum operands.
     */
    public static function formatObjectOrEnumCaseMessage(Context $context, Variable $operand): Value
    {
        $prefix = $context->builder->load(
            $context->constantStringFromString('Unhandled match case ')
        );
        $suffix = self::formatObjectOrEnumCaseSuffix($context, $operand);

        return JitStringConcat::concat($context, $prefix, $suffix);
    }

    private static function ofTypePrefix(Context $context, Value $className): Value
    {
        $prefix = $context->builder->load(
            $context->constantStringFromString('of type ')
        );

        return JitStringConcat::concat($context, $prefix, $className);
    }

    private static function loadObjectPtr(Context $context, Variable $operand): Value
    {
        if (Variable::TYPE_OBJECT === $operand->type) {
            return $context->helper->loadValue($operand);
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $operand);

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
    }

    private static function objectBuiltin(Context $context): Object_
    {
        return $context->type->object;
    }
}
