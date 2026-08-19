<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\CastObjectFromHashtableJit;
use PHPCompiler\JIT\Builtin\CastObjectValueBoxJit;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;

/**
 * Native-type (object) cast lowering — extracted from CastHelper (#10244).
 *
 * php-src: Zend/zend_operators.c — convert_to_object / cast_object (#30098, #30793, #32448, #32468).
 */
final class CastObjectNativeJit
{
    public static function emit(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        if (Variable::TYPE_OBJECT === $src->type) {
            return self::emitObjectOperand($context, $src);
        }
        if (Variable::TYPE_HASHTABLE === $src->type) {
            return CastObjectFromHashtableJit::emit($context, $src, $block, $op);
        }
        if (ArrayBuiltinHelper::isNativeArray($src->type)) {
            // convert_to_object(IS_ARRAY): packed int64[] was a compile abort (#32468).
            return self::emitNativeArray($context, $src, $block, $op);
        }
        if (Variable::TYPE_VALUE === $src->type) {
            return CastObjectValueBoxJit::emit($context, $src, $block, $op);
        }
        // Native literals stay unboxed; convert_to_object still wraps them as
        // stdClass->{scalar} (IS_NULL → empty). Boxed foreach operands already
        // hit TYPE_VALUE (#30098); (object)1 aborted as int64 (#32448).
        if (Variable::TYPE_NULL === $src->type) {
            return CastObjectFromHashtableJit::emitEmptyStdClass($context);
        }
        if (
            Variable::TYPE_NATIVE_LONG === $src->type
            || Variable::TYPE_NATIVE_DOUBLE === $src->type
            || Variable::TYPE_NATIVE_BOOL === $src->type
            || Variable::TYPE_STRING === $src->type
        ) {
            return CastObjectFromHashtableJit::emitScalarStdClass($context, $src);
        }

        throw new \LogicException(
            '(object) cast unsupported operand type in JIT: '.Variable::getStringType($src->type)
        );
    }

    /**
     * Packed native list → hashtable → stdClass (zend_operators.c convert_to_object IS_ARRAY).
     *
     * Define "0".."n-1" on stdClass before the hashtable copy so numeric keys are
     * addressable even when CFG literals were folded away (#32468).
     */
    private static function emitNativeArray(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $n = $src->nextFreeElement;
        for ($i = 0; $i < $n; ++$i) {
            $key = (string) $i;
            if (!$object->hasProperty($classId, $key)) {
                $object->defineProperty($classId, $key, Variable::TYPE_VALUE);
            }
        }
        $ht = HashTableHelper::materializeNativeArrayForCall($context, $src);
        $htVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht);

        return CastObjectFromHashtableJit::emit($context, $htVar, $block, $op);
    }

    /**
     * TYPE_OBJECT: real objects keep identity; Resource wrappers wrap as stdClass.scalar (#30793).
     */
    public static function emitObjectOperand(Context $context, Variable $src): Variable
    {
        $resourceClassId = self::resourceClassIdIfRegistered($context);
        if (null === $resourceClassId) {
            return new Variable(
                $context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $context->helper->loadValue($src)
            );
        }

        $objPtr = $context->helper->loadValue($src);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $isResource = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->constantFromInteger($resourceClassId, 'int64')
        );

        $resourceBlock = BasicBlockHelper::append($context, 'cast_object_res_wrap');
        $plainBlock = BasicBlockHelper::append($context, 'cast_object_res_plain');
        $mergeBlock = BasicBlockHelper::append($context, 'cast_object_res_merge');
        $doneBlock = BasicBlockHelper::append($context, 'cast_object_res_done');

        $context->builder->branchIf($isResource, $resourceBlock, $plainBlock);

        $context->builder->positionAtEnd($resourceBlock);
        $wrapped = CastObjectFromHashtableJit::emitScalarStdClass($context, $src);
        // allocate()/propertyStore may leave a different open block (#26818 / #30793).
        $resourceEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($plainBlock);
        $plain = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $objPtr
        );
        $plainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($wrapped->value->typeOf());
        $phi->addIncoming($wrapped->value, $resourceEnd);
        $phi->addIncoming($plain->value, $plainEnd);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $phi);
    }

    private static function resourceClassIdIfRegistered(Context $context): ?int
    {
        $object = $context->type->object;
        if (!$object instanceof ObjectBuiltin) {
            return null;
        }

        return $object->lookup('resource');
    }
}
