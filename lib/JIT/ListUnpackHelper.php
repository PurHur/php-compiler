<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\ext\standard\JitArrayIsList;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;

/** Runtime guard for `list()` / `[]` destructuring on non-array RHS (#4325, #4308, #7461); spread uses isList (#4298, #4841). */
final class ListUnpackHelper
{
    public const TYPE_ERROR_MESSAGE = 'Cannot unpack array with string keys';

    public const CALL_UNPACK_NON_ARRAY_MESSAGE = 'Only arrays and Traversables can be unpacked';

    public const LIST_DESTRUCT_STRING_MESSAGE = 'Cannot use string as array';

    public static function emitCallUnpackOperandCheck(Context $context, Variable $operand): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $isArray = self::isArrayValue($context, $operand);
        $failBb = BasicBlockHelper::append($context, 'call_unpack_non_array_fail');
        $okBb = BasicBlockHelper::append($context, 'call_unpack_non_array_ok');
        $context->builder->branchIf($isArray, $okBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        TypeErrorRaise::emitRaise($context, self::CALL_UNPACK_NON_ARRAY_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->builder->positionAtEnd($okBb);
    }

    public static function emitCheck(Context $context, Variable $array): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $isList = JitArrayIsList::invoke($context, $array);
        $failBb = BasicBlockHelper::append($context, 'list_unpack_fail');
        $okBb = BasicBlockHelper::append($context, 'list_unpack_ok');
        $context->builder->branchIf($isList, $okBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        TypeErrorRaise::emitRaise($context, self::TYPE_ERROR_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->builder->positionAtEnd($okBb);
        $context->builder->positionAtEnd($okBb);
    }

    /**
     * Guarded `[]` / list() destructuring: skip assign path when RHS is not an array at run time (#4325, #4308).
     * String RHS must TypeError (#7461, zend_execute.c is_list).
     *
     * @return bool true when assign-path opcodes should compile as unreachable stubs (compile-time non-array RHS)
     */
    public static function emitGuardedListUnpackCheck(
        Context $context,
        Variable $array,
        \PHPLLVM\BasicBlock $branchBlock,
        \PHPLLVM\BasicBlock $mergeEntry,
        ?Operand $arrayOp = null
    ): bool {
        if (self::isDefinitelyStringAtCompileTime($array)) {
            $context->builder->positionAtEnd($branchBlock);
            self::emitListDestructStringTypeError($context);

            return true;
        }
        if (self::isDefinitelyNonArrayAtCompileTime($context, $array, $arrayOp)) {
            $context->builder->positionAtEnd($branchBlock);
            $context->builder->branch($mergeEntry);
            $deadBb = BasicBlockHelper::append($context, 'list_unpack_skip_assign');
            $context->builder->positionAtEnd($deadBb);

            return true;
        }
        $isUnpackable = self::isListDestructUnpackableValue($context, $array, $arrayOp);
        $assignBb = BasicBlockHelper::append($context, 'list_unpack_array_check');
        $nonUnpackableBb = BasicBlockHelper::append($context, 'list_unpack_non_unpackable');
        $context->builder->positionAtEnd($branchBlock);
        $context->builder->branchIf($isUnpackable, $assignBb, $nonUnpackableBb);
        $context->builder->positionAtEnd($nonUnpackableBb);
        $isString = self::isStringValue($context, $array);
        $stringFailBb = BasicBlockHelper::append($context, 'list_unpack_string_fail');
        $context->builder->branchIf($isString, $stringFailBb, $mergeEntry);
        $context->builder->positionAtEnd($stringFailBb);
        self::emitListDestructStringTypeError($context);
        // Numeric list slots warn per-key at dim fetch; spread tail keeps isList in TYPE_LIST_SPREAD_ASSIGN (#4841).
        $context->builder->positionAtEnd($assignBb);

        return false;
    }

    public static function isDefinitelyNonArrayAtCompileTime(
        Context $context,
        Variable $array,
        ?Operand $arrayOp = null
    ): bool {
        if (self::isDefinitelyArrayAtCompileTime($array)) {
            return false;
        }
        if (ArrayAccessHelper::containerImplementsArrayAccess($context, $array, $arrayOp)) {
            return false;
        }
        if ($array->isNullConstant) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $array->type) {
            return ArrayAccessHelper::isKnownNonArrayAccessObject($context, $array, $arrayOp);
        }

        return Variable::TYPE_NULL === $array->type
            || Variable::TYPE_NATIVE_BOOL === $array->type
            || Variable::TYPE_NATIVE_LONG === $array->type
            || Variable::TYPE_NATIVE_DOUBLE === $array->type;
    }

    public static function isDefinitelyStringAtCompileTime(Variable $array): bool
    {
        return Variable::TYPE_STRING === $array->type;
    }

    public static function emitListDestructStringTypeError(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::LIST_DESTRUCT_STRING_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    public static function isListDestructUnpackableValue(
        Context $context,
        Variable $var,
        ?Operand $varOp = null
    ): \PHPLLVM\Value {
        $isArray = self::isArrayValue($context, $var);
        if (ArrayAccessHelper::containerImplementsArrayAccess($context, $var, $varOp)) {
            $i1 = $context->getTypeFromString('int1');

            return $context->builder->or(
                $isArray,
                $i1->constInt(1, false)
            );
        }
        if (ArrayAccessHelper::isKnownNonArrayAccessObject($context, $var, $varOp)) {
            return $isArray;
        }

        return $isArray;
    }

    public static function isDefinitelyArrayAtCompileTime(Variable $array): bool
    {
        return Variable::TYPE_HASHTABLE === $array->type
            || 0 !== ($array->type & Variable::IS_NATIVE_ARRAY);
    }

    public static function emitIsListBranchOrFail(Context $context, Variable $array): void
    {
        if (self::isDefinitelyNonArrayAtCompileTime($context, $array)) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $isList = JitArrayIsList::invoke($context, $array);
        $failBb = BasicBlockHelper::append($context, 'list_unpack_fail');
        $assignBb = BasicBlockHelper::append($context, 'list_unpack_assign');
        $context->builder->branchIf($isList, $assignBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        TypeErrorRaise::emitRaise($context, self::TYPE_ERROR_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->builder->positionAtEnd($assignBb);
    }

    public static function isArrayValue(Context $context, Variable $var): \PHPLLVM\Value
    {
        return self::isRuntimeTypeValue($context, $var, \PHPCompiler\VM\Variable::TYPE_ARRAY);
    }

    public static function isStringValue(Context $context, Variable $var): \PHPLLVM\Value
    {
        if (Variable::TYPE_STRING === $var->type) {
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(1, false);
        }

        return self::isRuntimeTypeValue($context, $var, \PHPCompiler\VM\Variable::TYPE_STRING);
    }

    private static function isRuntimeTypeValue(Context $context, Variable $var, int $type): \PHPLLVM\Value
    {
        $builder = $context->builder;
        $i1 = $context->getTypeFromString('int1');

        if (Variable::TYPE_HASHTABLE === $var->type || ($var->type & Variable::IS_NATIVE_ARRAY)) {
            return $i1->constInt($type === \PHPCompiler\VM\Variable::TYPE_ARRAY ? 1 : 0, false);
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $i1->constInt($type === \PHPCompiler\VM\Variable::TYPE_STRING ? 1 : 0, false);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
            $typeByte = $builder->load(
                $builder->structGep(
                    $valuePtr,
                    $context->structFieldMap['__value__']['type']
                )
            );
            $i8 = $context->getTypeFromString('int8');
            if ($type === \PHPCompiler\VM\Variable::TYPE_ARRAY) {
                $isVmArray = $builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt($type, false)
                );
                $isHt = $builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_HASHTABLE, false)
                );

                return $builder->or($isVmArray, $isHt);
            }

            if (\PHPCompiler\VM\Variable::TYPE_ARRAY === $type) {
                // __value__::type stores JIT Variable::TYPE_HASHTABLE (7|IS_REFCOUNTED), not VM TYPE_ARRAY (#9248).
                $isVmArray = $builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY, false)
                );
                $isJitHashtable = $builder->icmp(
                    \PHPLLVM\Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_HASHTABLE, false)
                );

                return $builder->or($isVmArray, $isJitHashtable);
            }

            return $builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt($type, false)
            );
        }

        return $i1->constInt(0, false);
    }
}
