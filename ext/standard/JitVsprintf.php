<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for vsprintf() (issue #3190).
 */
final class JitVsprintf
{
    private const VALUES_TYPE_ERROR = '%s(): Argument #2 ($values) must be of type array, %s given';

    public static function format(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('vsprintf() requires exactly two arguments');
        }
        self::requireValuesArrayArg($context, $args[1], 'vsprintf');
        $fmt = JitStringArg::lower($context, $args[0], 'vsprintf() format');
        $ht = ArrayBuiltinHelper::loadHashTable($context, $args[1]);
        $num = ArrayBuiltinHelper::getNumElements($context, $ht);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $emptyBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_empty');
        $packBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_pack');
        $doneBlock = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $packBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $nullArgv = $context->builder->pointerCast(
            $zero,
            $context->getTypeFromString('__value__*')
        );
        $emptyOut = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $zero,
            $nullArgv
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($packBlock);
        $valueTy = $context->getTypeFromString('__value__');
        $i32 = $context->getTypeFromString('int32');
        $elemSize = $context->builder->ptrToInt(
            $context->builder->gep(
                $valueTy->pointerType(0)->constNull(),
                $i32->constInt(1, false)
            ),
            $sizeT
        );
        $argvBytes = $context->builder->mul($elemSize, $context->builder->intCast($num, $sizeT));
        $argvRaw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $argvBytes
        );
        $argvPtr = $context->builder->pointerCast(
            $argvRaw,
            $context->getTypeFromString('__value__*')
        );
        $map = $context->structFieldMap['__hashtable__'];
        $valuesPtr = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );
        $idxAlloca = $context->builder->alloca($sizeT);
        $context->builder->store($context->builder->intCast($zero, $sizeT), $idxAlloca);
        $loopHead = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_loop_head');
        $loopBody = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_loop_body');
        $loopExit = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('vsprintf_loop_exit');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxAlloca);
        $done = $context->builder->icmp(Builder::INT_UGE, $context->builder->intCast($idx, $i64), $num);
        $context->builder->branchIf($done, $loopExit, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $idx64 = $context->builder->intCast($idx, $i64);
        $argvSlot = $context->builder->inBoundsGEP($argvPtr, $idx64);
        $srcSlot = $context->builder->inBoundsGEP($valuesPtr, $idx);
        JitValueBox::copyFromPointer($context, $argvSlot, $srcSlot);
        $one = $sizeT->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxAlloca
        );
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopExit);
        $packedOut = $context->builder->call(
            $context->lookupFunction('__compiler_sprintf'),
            $fmt,
            $num,
            $argvPtr
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $argvRaw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($emptyOut, $emptyBlock);
        $phi->addIncoming($packedOut, $loopExit);

        return $phi;
    }

    public static function requireValuesArrayArg(Context $context, JITVariable $arg, string $fn): void
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type
            || ($arg->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($loaded, $typeField)
            );
            $i8 = $context->getTypeFromString('int8');
            $isArrayType = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_ARRAY, false)
            );
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $loaded
            );
            $hasHt = $context->builder->icmp(
                Builder::INT_NE,
                $ht,
                $ht->typeOf()->constNull()
            );
            $isArray = $context->builder->or($isArrayType, $hasHt);
            $okBlock = BasicBlockHelper::append($context, 'vsprintf_values_ok');
            $errBlock = BasicBlockHelper::append($context, 'vsprintf_values_err');
            $context->builder->branchIf($isArray, $okBlock, $errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitBoxedValuesTypeError($context, $fn, $typeByte);
            $context->builder->positionAtEnd($okBlock);

            return;
        }

        self::emitValuesTypeErrorAndAbort($context, $fn, self::jitGivenTypeName($arg->type));
    }

    private static function emitBoxedValuesTypeError(Context $context, string $fn, Value $typeByte): void
    {
        $i8 = $context->getTypeFromString('int8');
        $nullBlock = BasicBlockHelper::append($context, 'vsprintf_values_null');
        $stringBlock = BasicBlockHelper::append($context, 'vsprintf_values_string');
        $objectBlock = BasicBlockHelper::append($context, 'vsprintf_values_object');
        $intBlock = BasicBlockHelper::append($context, 'vsprintf_values_int');
        $floatBlock = BasicBlockHelper::append($context, 'vsprintf_values_float');
        $boolBlock = BasicBlockHelper::append($context, 'vsprintf_values_bool');
        $mixedBlock = BasicBlockHelper::append($context, 'vsprintf_values_mixed');
        $afterNull = BasicBlockHelper::append($context, 'vsprintf_values_after_null');
        $afterString = BasicBlockHelper::append($context, 'vsprintf_values_after_string');
        $afterObject = BasicBlockHelper::append($context, 'vsprintf_values_after_object');
        $afterInt = BasicBlockHelper::append($context, 'vsprintf_values_after_int');
        $afterFloat = BasicBlockHelper::append($context, 'vsprintf_values_after_float');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'null');

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);
        $context->builder->positionAtEnd($stringBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'string');

        $context->builder->positionAtEnd($afterString);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);
        $context->builder->positionAtEnd($objectBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'object');

        $context->builder->positionAtEnd($afterObject);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isInt, $intBlock, $afterInt);
        $context->builder->positionAtEnd($intBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'int');

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf($isFloat, $floatBlock, $afterFloat);
        $context->builder->positionAtEnd($floatBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'float');

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_BOOLEAN, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $mixedBlock);
        $context->builder->positionAtEnd($boolBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'bool');
        $context->builder->positionAtEnd($mixedBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'mixed');
    }

    private static function emitValuesTypeErrorAndAbort(Context $context, string $fn, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, \sprintf(self::VALUES_TYPE_ERROR, $fn, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitGivenTypeName(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
