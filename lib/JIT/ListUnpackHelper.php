<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitArrayIsList;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;

/** Runtime guard for `list()` / `[]` destructuring on non-list arrays (#4298) and non-array RHS (#4325). */
final class ListUnpackHelper
{
    public const TYPE_ERROR_MESSAGE = 'Cannot unpack array with string keys';

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

    public static function emitIsListBranchOrFail(Context $context, Variable $array): void
    {
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
                $i8->constInt(Variable::TYPE_HASHTABLE, false)
            );
        }

        return $i1->constInt(0, false);
    }
}
