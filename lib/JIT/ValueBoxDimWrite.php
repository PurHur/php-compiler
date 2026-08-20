<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\VM\StringOffsetJitHelper;
use PHPCfg\Operand;

/**
 * String-offset FETCH_DIM_W for {@see Variable::TYPE_VALUE} boxes typed as string (#32764).
 *
 * Read path already branches on the string type tag ({@see Variable::dimFetchValueBoxRead},
 * #22646). Writes must not call {@see HashTableHelper::ensureHashtablePointer} first — that
 * allocates an empty hashtable and {@see __value__writeHashtable} clobbers the string, so
 * `$s='abc'; $s[1]='Z'` becomes `array(1 => 'Z')` under thin AOT.
 *
 * php-src: Zend/zend_execute.c — zend_assign_to_string_offset / ZEND_ASSIGN_DIM
 */
final class ValueBoxDimWrite
{
    /**
     * Emit a writable char* lvalue into {@see $resultOp} for a value box holding a string.
     *
     * Separates the string (COW), writes it back into the box, then exposes the byte pointer
     * so {@see StringOffsetHelper::dimAssign} via {@see \PHPCompiler\JIT::assignOperand} stores
     * the RHS into the string in place.
     */
    public static function fetchStringOffsetWriteLvalue(
        Context $context,
        Variable $container,
        Variable $dim,
        Operand $resultOp
    ): void {
        $ptr = JitValueBox::valuePtrFromVariable($context, $container);

        if (Variable::TYPE_STRING === $dim->type || Variable::TYPE_OBJECT === $dim->type) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            $label = 'string';
            if (Variable::TYPE_OBJECT === $dim->type) {
                $label = $dim->classUserType ?? 'object';
            }
            ErrorRaise::emitRaise(
                $context,
                StringOffsetJitHelper::illegalDimTypeErrorMessage($label)
            );
            $context->makeVariableFromValueOp(
                $context->getTypeFromString('int8*')->constNull(),
                $resultOp
            );

            return;
        }
        if (Variable::emitIllegalStringOffsetDimGuard($context, $dim)) {
            $context->makeVariableFromValueOp(
                $context->getTypeFromString('int8*')->constNull(),
                $resultOp
            );

            return;
        }
        $dimLong = Variable::coerceStringOffsetDimToLong($context, $dim);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $ptr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $charPtr = StringOffsetHelper::dimFetch($context, $owned, $dimLong);
        $context->makeVariableFromValueOp($charPtr, $resultOp);
    }

    /**
     * True when CFG types the dim container as string (boxed locals keep TYPE_VALUE).
     */
    public static function containerCfgIsString(?\PHPTypes\Type $type): bool
    {
        return null !== $type && \PHPTypes\Type::TYPE_STRING === $type->type;
    }

    /**
     * Copy the live dim payload into the FETCH_DIM_W orphan before .= / assign-op (#32800).
     */
    public static function hydrateMaybeStringOrHtLvalue(Context $context, Variable $lvalue): void
    {
        $container = $lvalue->writableRuntimeStringOrHtContainer;
        $dim = $lvalue->writableRuntimeStringOrHtDim;
        if (null === $container || null === $dim || null === $lvalue->value) {
            return;
        }

        $ptr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($ptr, $map['type'])
        );
        $isString = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $context->builder->and($typeByte, $i8->constInt(0x7f, false)),
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );

        $tag = 'vb_dim_hyd_'.(string) self::nextSeq();
        $strBlock = BasicBlockHelper::append($context, $tag.'_str');
        $htBlock = BasicBlockHelper::append($context, $tag.'_ht');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isString, $strBlock, $htBlock);

        $context->builder->positionAtEnd($strBlock);
        $dimLong = Variable::coerceStringOffsetDimToLong($context, $dim);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $ptr
        );
        $charStr = StringOffsetHelper::readDimAsString($context, $str, $dimLong);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $lvalue->value),
            $charStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($htBlock);
        $ht = HashTableHelper::loadHashtablePointer($context, $container);
        $index = Variable::materializePackedIndex($context, $dim, true);
        $tmp = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $index);
        JitValueBox::copyFromPointer(
            $context,
            $lvalue->value,
            JitValueBox::pointer($context, $tmp->value)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * FETCH_DIM_W placeholder when CFG says string but the VALUE box may be a HT (#32800).
     *
     * Function-static `static $a = ['x']` is CFG-typed {@code string} while
     * {@see FunctionStaticHelper::writeDefault} stores a hashtable. Unconditional
     * {@see fetchStringOffsetWriteLvalue} then clobbers the HT and SIGSEGVs on assign.
     * Defer string-vs-HT to {@see assignByRuntimeTag} when the RHS is available.
     */
    public static function fetchMaybeStringOrHtWriteLvalue(
        Context $context,
        Variable $container,
        Variable $dim,
        Operand $resultOp
    ): void {
        $slot = JitValueBox::alloc($context);
        $marker = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $marker->writableRuntimeStringOrHtContainer = $container;
        $marker->writableRuntimeStringOrHtDim = $dim;
        $context->setVariableOp($resultOp, $marker);
    }

    /**
     * Assign into a {@see Variable::$writableRuntimeStringOrHtContainer} lvalue (#32800).
     */
    public static function assignByRuntimeTag(
        Context $context,
        Variable $lvalue,
        Variable $rhs
    ): void {
        $container = $lvalue->writableRuntimeStringOrHtContainer;
        $dim = $lvalue->writableRuntimeStringOrHtDim;
        if (null === $container || null === $dim) {
            throw new \LogicException('assignByRuntimeTag requires container+dim markers (#32800)');
        }

        $ptr = JitValueBox::valuePtrFromVariable($context, $container);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($ptr, $map['type'])
        );
        $isString = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $context->builder->and($typeByte, $i8->constInt(0x7f, false)),
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );

        $tag = 'vb_dim_w_'.(string) self::nextSeq();
        $strBlock = BasicBlockHelper::append($context, $tag.'_str');
        $htBlock = BasicBlockHelper::append($context, $tag.'_ht');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isString, $strBlock, $htBlock);

        $context->builder->positionAtEnd($strBlock);
        self::assignStringOffsetIntoBox($context, $container, $dim, $rhs);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($htBlock);
        $ht = HashTableHelper::loadHashtablePointer($context, $container);
        $index = Variable::materializePackedIndex($context, $dim, true);
        HashTableHelper::setAtIndex($context, $ht, $index, $rhs);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /** String-offset assign into a VALUE box (COW separate + byte store). */
    private static function assignStringOffsetIntoBox(
        Context $context,
        Variable $container,
        Variable $dim,
        Variable $rhs
    ): void {
        $ptr = JitValueBox::valuePtrFromVariable($context, $container);
        if (Variable::TYPE_STRING === $dim->type || Variable::TYPE_OBJECT === $dim->type) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            $label = 'string';
            if (Variable::TYPE_OBJECT === $dim->type) {
                $label = $dim->classUserType ?? 'object';
            }
            ErrorRaise::emitRaise(
                $context,
                StringOffsetJitHelper::illegalDimTypeErrorMessage($label)
            );

            return;
        }
        if (Variable::emitIllegalStringOffsetDimGuard($context, $dim)) {
            return;
        }
        $dimLong = Variable::coerceStringOffsetDimToLong($context, $dim);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $ptr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $charPtr = StringOffsetHelper::dimFetch($context, $owned, $dimLong);
        StringOffsetHelper::dimAssign($context, $charPtr, $rhs);
    }

    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }
}
