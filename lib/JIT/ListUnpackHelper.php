<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitArrayIsList;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;

/** Runtime guard for `list()` / `[]` destructuring on non-list arrays (#4298) and non-array RHS (#4325, #4308). */
final class ListUnpackHelper
{
    public const TYPE_ERROR_MESSAGE = 'Cannot unpack array with string keys';

    public const CALL_UNPACK_NON_ARRAY_MESSAGE = 'Only arrays and Traversables can be unpacked';

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
     *
     * @return bool true when assign-path opcodes should compile as unreachable stubs (compile-time non-array RHS)
     */
    public static function emitGuardedListUnpackCheck(
        Context $context,
        Variable $array,
        \PHPLLVM\BasicBlock $branchBlock,
        \PHPLLVM\BasicBlock $mergeEntry
    ): bool {
        if (self::isDefinitelyNonArrayAtCompileTime($array)) {
            $context->builder->positionAtEnd($branchBlock);
            $context->builder->branch($mergeEntry);
            $deadBb = BasicBlockHelper::append($context, 'list_unpack_skip_assign');
            $context->builder->positionAtEnd($deadBb);

            return true;
        }
        $isArray = self::isArrayValue($context, $array);
        $arrayCheckBb = BasicBlockHelper::append($context, 'list_unpack_array_check');
        $context->builder->positionAtEnd($branchBlock);
        $context->builder->branchIf($isArray, $arrayCheckBb, $mergeEntry);
        $context->builder->positionAtEnd($arrayCheckBb);
        self::emitIsListBranchOrFail($context, $array);

        return false;
    }

    public static function isDefinitelyNonArrayAtCompileTime(Variable $array): bool
    {
        if (self::isDefinitelyArrayAtCompileTime($array)) {
            return false;
        }

        return Variable::TYPE_STRING === $array->type
            || Variable::TYPE_OBJECT === $array->type
            || Variable::TYPE_NULL === $array->type
            || Variable::TYPE_NATIVE_BOOL === $array->type
            || Variable::TYPE_NATIVE_LONG === $array->type
            || Variable::TYPE_NATIVE_DOUBLE === $array->type;
    }

    public static function isDefinitelyArrayAtCompileTime(Variable $array): bool
    {
        return Variable::TYPE_HASHTABLE === $array->type
            || 0 !== ($array->type & Variable::IS_NATIVE_ARRAY);
    }

    public static function emitIsListBranchOrFail(Context $context, Variable $array): void
    {
        if (self::isDefinitelyNonArrayAtCompileTime($array)) {
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
        $builder = $context->builder;
        $i1 = $context->getTypeFromString('int1');

        if (Variable::TYPE_HASHTABLE === $var->type || ($var->type & Variable::IS_NATIVE_ARRAY)) {
            return $i1->constInt(1, false);
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

            return $builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $typeByte,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY, false)
            );
        }

        return $i1->constInt(0, false);
    }
}
