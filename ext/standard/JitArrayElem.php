<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ArrayElemRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helpers for array builtins — array_first/last route through PHP (#15063). */
final class JitArrayElem
{
    private const TYPE_ERROR = '%s(): Argument #1 ($array) must be of type array, %s given';

    private const TYPE_ERROR_N = '%s(): Argument #%d ($%s) must be of type %s, %s given';

    private const TYPE_ERROR_ARGNUM = '%s(): Argument #%d must be of type array, %s given';

    public static function first(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'array_first');

        return ArrayElemRuntime::first($context, $array);
    }

    public static function last(Context $context, JITVariable $array): Value
    {
        self::requireArrayArg($context, $array, 'array_last');

        return ArrayElemRuntime::last($context, $array);
    }

    public static function requireArrayArg(Context $context, JITVariable $array, string $fn): void
    {
        self::requireArrayParam($context, $array, $fn, 1, 'array');
    }

    /** Variadic array builtins whose Zend messages omit ($param) — e.g. array_merge(); later args of array_diff(). */
    public static function requireArrayArgNum(Context $context, JITVariable $array, string $fn, int $argNum): void
    {
        if (JITVariable::TYPE_HASHTABLE === $array->type
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (JITVariable::TYPE_NULL === $array->type || ($array->isNullConstant ?? false)) {
            self::emitArgNumErrorAndAbort($context, $fn, $argNum, 'null');

            return;
        }
        if (JITVariable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            self::requireArrayArgNumBoxed($context, $array, $fn, $argNum);

            return;
        }
        self::emitArgNumErrorAndAbort($context, $fn, $argNum, self::jitTypeLabel($array->type));
    }

    public static function requireArrayParam(
        Context $context,
        JITVariable $array,
        string $fn,
        int $argNum,
        string $paramName,
        string $expectedType = 'array'
    ): void {
        if (JITVariable::TYPE_HASHTABLE === $array->type
            || ($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return;
        }
        if (JITVariable::TYPE_NULL === $array->type || ($array->isNullConstant ?? false)) {
            self::emitErrorAndAbort(
                $context,
                \sprintf(self::TYPE_ERROR_N, $fn, $argNum, $paramName, $expectedType, 'null')
            );

            return;
        }
        if (JITVariable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $array);
            $isArray = self::valueBoxIsArray($context, $loaded);
            $okBlock = BasicBlockHelper::append($context, 'array_elem_req_ok');
            $errBlock = BasicBlockHelper::append($context, 'array_elem_req_err');
            $context->builder->branchIf($isArray, $okBlock, $errBlock);
            $context->builder->positionAtEnd($errBlock);
            // Boxed null under try/catch SSA must say "null given", not "mixed" (#27447 / #27448).
            $typeField = $context->structFieldMap['__value__']['type'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($loaded, $typeField)
            );
            self::emitBoxedNonArrayTypeErrorParam(
                $context,
                $fn,
                $argNum,
                $paramName,
                $expectedType,
                $typeByte
            );
            $context->builder->positionAtEnd($okBlock);

            return;
        }
        $okBlock = BasicBlockHelper::append($context, 'array_req_ok');
        $errBlock = BasicBlockHelper::append($context, 'array_req_err');
        $context->builder->branch($errBlock);
        $context->builder->positionAtEnd($errBlock);
        self::emitErrorAndAbort(
            $context,
            \sprintf(
                self::TYPE_ERROR_N,
                $fn,
                $argNum,
                $paramName,
                $expectedType,
                self::jitTypeLabel($array->type)
            )
        );
        $context->builder->positionAtEnd($okBlock);
    }

    private static function requireArrayArgNumBoxed(
        Context $context,
        JITVariable $array,
        string $fn,
        int $argNum
    ): void {
        $loaded = JitValueBox::valuePtrFromVariable($context, $array);
        $isArray = self::valueBoxIsArray($context, $loaded);
        $okBlock = BasicBlockHelper::append($context, 'array_argnum_req_ok');
        $errBlock = BasicBlockHelper::append($context, 'array_argnum_req_err');
        $context->builder->branchIf($isArray, $okBlock, $errBlock);
        $context->builder->positionAtEnd($errBlock);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        self::emitBoxedNonArrayTypeErrorArgNum($context, $fn, $argNum, $typeByte);
        $context->builder->positionAtEnd($okBlock);
    }

    /**
     * True when a boxed __value__* holds a hashtable array (VM tag 6 or JIT TYPE_HASHTABLE).
     */
    private static function valueBoxIsArray(Context $context, Value $loaded): Value
    {
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ARRAY, false)
        );
        $isJitHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_HASHTABLE, false)
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

        return $context->builder->or(
            $isVmArray,
            $context->builder->or($isJitHt, $hasHt)
        );
    }

    private static function emitBoxedNonArrayTypeErrorArgNum(
        Context $context,
        string $fn,
        int $argNum,
        Value $typeByte
    ): void {
        self::emitBoxedNonArrayTypeError(
            $context,
            $typeByte,
            static function (string $given) use ($context, $fn, $argNum): void {
                self::emitArgNumErrorAndAbort($context, $fn, $argNum, $given);
            },
            'array_argnum_req'
        );
    }

    /**
     * Named-param TypeError for boxed non-array (#27448 — in_array/array_key_exists haystack).
     */
    private static function emitBoxedNonArrayTypeErrorParam(
        Context $context,
        string $fn,
        int $argNum,
        string $paramName,
        string $expectedType,
        Value $typeByte
    ): void {
        self::emitBoxedNonArrayTypeError(
            $context,
            $typeByte,
            static function (string $given) use ($context, $fn, $argNum, $paramName, $expectedType): void {
                self::emitErrorAndAbort(
                    $context,
                    \sprintf(self::TYPE_ERROR_N, $fn, $argNum, $paramName, $expectedType, $given)
                );
            },
            'array_param_req'
        );
    }

    /**
     * @param callable(string):void $emitGiven
     */
    private static function emitBoxedNonArrayTypeError(
        Context $context,
        Value $typeByte,
        callable $emitGiven,
        string $prefix
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $nullBlock = BasicBlockHelper::append($context, $prefix.'_null');
        $afterNull = BasicBlockHelper::append($context, $prefix.'_after_null');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);
        $context->builder->positionAtEnd($nullBlock);
        $emitGiven('null');

        $stringBlock = BasicBlockHelper::append($context, $prefix.'_string');
        $afterString = BasicBlockHelper::append($context, $prefix.'_after_string');
        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);
        $context->builder->positionAtEnd($stringBlock);
        $emitGiven('string');

        $intBlock = BasicBlockHelper::append($context, $prefix.'_int');
        $afterInt = BasicBlockHelper::append($context, $prefix.'_after_int');
        $context->builder->positionAtEnd($afterString);
        $isInt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_INTEGER, false)
        );
        $context->builder->branchIf($isInt, $intBlock, $afterInt);
        $context->builder->positionAtEnd($intBlock);
        $emitGiven('int');

        $floatBlock = BasicBlockHelper::append($context, $prefix.'_float');
        $afterFloat = BasicBlockHelper::append($context, $prefix.'_after_float');
        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf($isFloat, $floatBlock, $afterFloat);
        $context->builder->positionAtEnd($floatBlock);
        $emitGiven('float');

        $boolBlock = BasicBlockHelper::append($context, $prefix.'_bool');
        $mixedBlock = BasicBlockHelper::append($context, $prefix.'_mixed');
        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_BOOLEAN, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $mixedBlock);
        $context->builder->positionAtEnd($boolBlock);
        $emitGiven('bool');
        $context->builder->positionAtEnd($mixedBlock);
        $emitGiven('mixed');
    }

    private static function emitArgNumErrorAndAbort(Context $context, string $fn, int $argNum, string $given): void
    {
        self::emitErrorAndAbort(
            $context,
            \sprintf(self::TYPE_ERROR_ARGNUM, $fn, $argNum, $given)
        );
    }

    /**
     * Catchable under AOT try/catch; fatal when uncaught (#27448 / #27446).
     * Bare pending-raise + abort aborts exit 134 inside try — use ExceptionBridge.
     */
    private static function emitErrorAndAbort(Context $context, string $message): void
    {
        // Catchable in try/catch; uncaught AOT uses abort_if_pending (not raw abort) (#27447 / #27448).
        ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_elem_te_cont');
    }

    private static function jitTypeLabel(int $type): string
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
