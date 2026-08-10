<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\VM\VmIsset;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for empty($container[$dim]) (#14798).
 */
final class EmptyDimensionLlvm
{
    public static function compile(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $dimOp = null,
        ?Operand $containerOp = null
    ): Value {
        if (VmIsset::issetOnPropertyRejectsArrayContainer($container, $containerOp, false)) {
            return $context->constantFromBool(true);
        }
        if (Variable::TYPE_OBJECT === $container->type) {
            $arrayAccessEmpty = ArrayAccessHelper::tryCompileOffsetIsEmpty(
                $context,
                $container,
                $dim,
                $containerOp
            );
            if (null !== $arrayAccessEmpty) {
                return $arrayAccessEmpty;
            }
            $propName = VmIsset::literalStringKey($dimOp);
            if (
                null !== $propName
                && null !== $containerOp
                && null !== $containerOp->type
                && Type::TYPE_OBJECT === $containerOp->type->type
            ) {
                return EmptyObjectPropertyHelper::compile(
                    $context,
                    $container,
                    $dim,
                    $dimOp,
                    $containerOp
                );
            }
        }
        if ($container->type === Variable::TYPE_STRING) {
            return self::compileStringOffsetIsEmpty($context, $container, $dim);
        }
        if ($container->type & Variable::IS_NATIVE_ARRAY) {
            return self::compileNativeArrayOffsetIsEmpty($context, $container, $dim);
        }
        if ($container->type === Variable::TYPE_HASHTABLE || Variable::TYPE_VALUE === $container->type) {
            $htVar = Variable::TYPE_VALUE === $container->type
                ? self::hashtableFromValueBox($context, $container)
                : $container;

            return self::compileHashTableOffsetIsEmpty($context, $htVar, $dim, $containerOp);
        }

        $isset = IssetHelper::compile($context, $container, $dim, $dimOp, $containerOp, false);

        return $context->builder->not($isset);
    }

    private static function compileStringOffsetIsEmpty(Context $context, Variable $container, Variable $dim): Value
    {
        if (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            return $context->constantFromBool(true);
        }
        $str = $context->helper->loadValue($container);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $index = $context->helper->loadValue($dim);
        $offset = StringOffsetHelper::normalizeOffset($context, $index, $len);
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $nonNeg = $context->builder->icmp(Builder::INT_UGE, $offset, $zero);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $offset, $len);
        $isset = $context->builder->and($inRange, $nonNeg);

        $tag = 'str_empty_'.(string) spl_object_id($context);
        $oobBlock = BasicBlockHelper::append($context, $tag.'_oob');
        $inBlock = BasicBlockHelper::append($context, $tag.'_in');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $i1 = $context->getTypeFromString('int1');

        $context->builder->branchIf($isset, $inBlock, $oobBlock);

        $context->builder->positionAtEnd($oobBlock);
        $oobEmpty = $i1->constInt(1, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($inBlock);
        $charStr = StringOffsetHelper::dimFetch($context, $str, $dim);
        $charVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $charStr);
        $valueEmpty = EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $charVar);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming([$oobEmpty, $oobBlock]);
        $phi->addIncoming([$valueEmpty, $inBlock]);

        return $phi;
    }

    private static function compileNativeArrayOffsetIsEmpty(
        Context $context,
        Variable $container,
        Variable $dim
    ): Value {
        if (Variable::TYPE_NATIVE_LONG !== $dim->type) {
            return $context->constantFromBool(true);
        }
        $index = $context->helper->loadValue($dim);
        $size = $context->constantFromInteger($container->nextFreeElement, 'int32');
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));
        $exists = $context->builder->and($inRange, $nonNeg);

        $tag = 'narr_empty_'.(string) spl_object_id($context);
        $missingBlock = BasicBlockHelper::append($context, $tag.'_missing');
        $presentBlock = BasicBlockHelper::append($context, $tag.'_present');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $i1 = $context->getTypeFromString('int1');

        $context->builder->branchIf($exists, $presentBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $missingEmpty = $i1->constInt(1, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($presentBlock);
        $elemPtr = $context->builder->gep(
            $container->value,
            [$context->constantFromInteger(0, 'int32'), $index]
        );
        $elemVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $elemPtr
        );
        $valueEmpty = EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $elemVar);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming([$missingEmpty, $missingBlock]);
        $phi->addIncoming([$valueEmpty, $presentBlock]);

        return $phi;
    }

    private static function compileHashTableOffsetIsEmpty(
        Context $context,
        Variable $container,
        Variable $dim,
        ?Operand $containerOp
    ): Value {
        $container = HashTableHelper::asDetachedHashtable($context, $container);
        if (Variable::TYPE_STRING === $container->type) {
            return self::compileStringOffsetIsEmpty($context, $container, $dim);
        }
        $superglobalName = VmIsset::superglobalName($container, $containerOp, VmIsset::isSelfHostAot());
        $ht = HashTableHelper::loadHashtablePointer($context, $container);

        // ZEND_ISSET_ISEMPTY_DIM_OBJ — illegal offsets share isset/empty TypeError text (#29549).
        if (Variable::TYPE_HASHTABLE === $dim->type) {
            HashTableHelper::emitIllegalOffsetTypeForKey(
                $context,
                $dim,
                'Illegal offset type in isset or empty'
            );

            return $context->constantFromBool(true);
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            return self::compileHashTableObjectDimIsEmpty($context, $ht, $dim);
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            // Runtime object/array/enum keys: same TypeError as isset (#29549 / #29567).
            HashTableHelper::offsetIsSetDim($context, $ht, $dim);
        }

        $read = HashTableHelper::readDimToValueBox($context, $ht, $dim, $superglobalName);

        return EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $read);
    }

    /**
     * empty($arr[$object]) — resource warn+cast, else TypeError with isset/empty wording (#29549).
     */
    private static function compileHashTableObjectDimIsEmpty(
        Context $context,
        Value $ht,
        Variable $dim
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $done = BasicBlockHelper::append($context, 'ht_empty_obj_dim_done');
        HashTableResourceKeyLlvm::emitObjectDimOrIllegal(
            $context,
            $dim,
            'Illegal offset type in isset or empty',
            static function (Value $index) use ($context, $ht, $resultSlot, $done): void {
                $box = HashTableHelper::readIndexedToValueBox($context, $ht, $index);
                $empty = EmptyObjectPropertyLlvm::compileEmptyFromValue($context, $box);
                $context->builder->store($empty, $resultSlot);
                $context->builder->branch($done);
            }
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function hashtableFromValueBox(Context $context, Variable $container): Variable
    {
        $ht = HashTableHelper::readHashtableFromValueBox($context, $container);

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }
}
