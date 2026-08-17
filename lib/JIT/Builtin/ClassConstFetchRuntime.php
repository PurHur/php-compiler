<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReadonlyBridge;
use PHPCompiler\JIT\Variable;
use PHPCompiler\ext\standard\strtolower as StdStrtolower;
use PHPCfg\Operand;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT dynamic class const fetch runtime lowering split out of {@see ClassConstFetchHelper} (#10200).
 *
 * This keeps the high-level emit API small while preserving current lowering behavior.
 */
final class ClassConstFetchRuntime
{
    /**
     * Lower dynamic class constant fetch with runtime class id and runtime name.
     *
     * @return Variable TYPE_VALUE box
     */
    public static function fetchDynamicByClassIdValue(
        Object_ $objectType,
        Value $classIdVal,
        Variable $nameVar,
        Operand $classOp,
        ?Block $block = null,
        ?\PHPCompiler\JIT $jit = null,
        ?Variable $classVar = null
    ): Variable {
        $context = $objectType->jitContext();
        StringCaseCompare::ensureStrcasecmpLinked($context);
        ReadonlyBridge::ensureLinked($context);

        $nativeName = JitStringArg::lower($context, $nameVar, 'class constant name');
        $lcName = (new StdStrtolower())->call(
            $context,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nativeName)
        );
        $resultSlot = JitValueBox::alloc($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $entry = $context->builder->getInsertBlock();
        $merge = $fn->appendBasicBlock('class_const_dyn_merge');
        $fail = $fn->appendBasicBlock('class_const_dyn_fail');

        $classPseudo = $context->builder->load($context->constantStringFromString('class'));
        $context->builder->positionAtEnd($entry);
        $isClass = $context->builder->call(
            $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
            self::stringDataPtr($context, $nativeName),
            self::stringDataPtr($context, $classPseudo)
        );
        $i32 = $context->getTypeFromString('int32');
        $classMatch = $fn->appendBasicBlock('class_const_dyn_class');
        $constChain = $fn->appendBasicBlock('class_const_dyn_chain');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $isClass, $i32->constInt(0, false)),
            $classMatch,
            $constChain
        );

        $context->builder->positionAtEnd($classMatch);
        if (null !== $classVar) {
            $classNameStr = ClassConstFetchHelper::emitClassPseudoConstStringValue($objectType, $block, $classVar);
        } elseif ($classOp instanceof Operand\Literal) {
            $classNameStr = $context->builder->load(
                $context->constantStringFromString($classOp->value)
            );
        } else {
            $classNameStr = ClassConstFetchHelper::emitClassNameStringFromClassId($objectType, $classIdVal);
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $resultSlot),
            $classNameStr
        );
        $context->builder->branch($merge);

        $checkBlock = $constChain;
        $context->builder->positionAtEnd($constChain);
        $htPtrType = $context->getTypeFromString('__hashtable__*');
        $ht = $htPtrType->constNull();
        foreach ($objectType->allClassNamesById() as $id => $_) {
            if ([] === $objectType->classConstantsForId($id)) {
                continue;
            }
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classIdVal, $expectedId);
            $candidate = $objectType->classConstMapPointerForId($id);
            $ht = $context->builder->select($isId, $candidate, $ht);
        }
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $lcName
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $valPtr, $valPtr->typeOf()->constNull());
        $hasValue = $fn->appendBasicBlock('class_const_dyn_has_value');
        $context->builder->branchIf($isNull, $fail, $hasValue);

        $context->builder->positionAtEnd($hasValue);
        JitValueBox::copyFromPointer($context, $resultSlot, $valPtr);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($fail);
        $displayClass = self::displayClassName($objectType, -1, $classOp);
        $message = "Undefined constant {$displayClass}::*";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            ClassConstFetchHelper::messageDataPtrForRuntime($context, $message),
            $context->constantFromInteger(strlen($message), 'size_t')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($merge);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $resultSlot
        );
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strPtr, $map['value']);
    }

    private static function displayClassName(Object_ $objectType, int $classId, Operand $classOp): string
    {
        if ($classOp instanceof Operand\Literal) {
            return $classOp->value;
        }
        if ($classId < 0) {
            return '*';
        }

        return $objectType->classNameForId($classId);
    }
}

