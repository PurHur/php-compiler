<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM array-arg guard for vsprintf()/vprintf() values parameter (#13589, #15989). */
final class JitVsprintfArrayArg
{
    private const VALUES_TYPE_ERROR = '%s(): Argument #%d ($values) must be of type array, %s given';

    public static function requireValues(Context $context, JITVariable $arg, string $fn, int $argNum = 2): void
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
            self::emitBoxedValuesTypeError($context, $fn, $typeByte, $argNum);
            $context->builder->positionAtEnd($okBlock);

            return;
        }

        self::emitValuesTypeErrorAndAbort($context, $fn, self::jitGivenTypeName($arg->type), $argNum);
    }

    private static function emitBoxedValuesTypeError(Context $context, string $fn, Value $typeByte, int $argNum): void
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
        self::emitValuesTypeErrorAndAbort($context, $fn, 'null', $argNum);

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);
        $context->builder->positionAtEnd($stringBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'string', $argNum);

        $context->builder->positionAtEnd($afterString);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);
        $context->builder->positionAtEnd($objectBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'object', $argNum);

        $context->builder->positionAtEnd($afterObject);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isInt, $intBlock, $afterInt);
        $context->builder->positionAtEnd($intBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'int', $argNum);

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf($isFloat, $floatBlock, $afterFloat);
        $context->builder->positionAtEnd($floatBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'float', $argNum);

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_BOOLEAN, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $mixedBlock);
        $context->builder->positionAtEnd($boolBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'bool', $argNum);
        $context->builder->positionAtEnd($mixedBlock);
        self::emitValuesTypeErrorAndAbort($context, $fn, 'mixed', $argNum);
    }

    private static function emitValuesTypeErrorAndAbort(Context $context, string $fn, string $given, int $argNum): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, \sprintf(self::VALUES_TYPE_ERROR, $fn, $argNum, $given));
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
