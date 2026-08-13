<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\CastArrayShared;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPLLVM\Value;

/**
 * (object) cast from hashtable / stdClass materialization (#10046).
 *
 * php-src: Zend/zend_operators.c — cast_object
 */
final class CastObjectFromHashtableJit
{
    public static function emit(
        Context $context,
        Variable $src,
        Block $block,
        OpCode $op
    ): Variable {
        CastArrayShared::ensureInsertBlock($context, 'cast_object_body');
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $srcOp = $block->getOperand($op->arg2);
        $literalKeys = self::literalArrayKeysFromBlock($block, $srcOp);
        foreach ($literalKeys as $key) {
            if ($object->hasProperty($classId, $key)) {
                continue;
            }
            $object->defineProperty($classId, $key, Variable::TYPE_VALUE);
        }
        // allocate() may split CFG (prop_value_init/done). Stay on the block allocate left
        // open — never rewind to a pre-allocate insert that now holds a terminator (#26818).
        $objVal = $object->allocate($classId);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'cast_object_after_alloc');
        $object->markObjectConstructed($objVal);
        $ht = $context->helper->loadValue($src);
        $className = 'stdClass';
        $voidPtr = $context->getTypeFromString('void*');
        foreach ($object->instancePropertySets($classId) as $propset) {
            $propName = $propset[1];
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            $valEntry = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringKeyValue'),
                $ht,
                $keyStr
            );
            // Point the property slot at the hashtable value box (array is consumed by the
            // cast). propertyStore copy paths segfault under AOT for nested object values (#26818).
            $slot = $object->propertySlotFor($objVal, $className, $propName);
            $context->builder->store(
                $context->builder->pointerCast($valEntry, $voidPtr),
                $slot
            );
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'cast_object_after_props');

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objVal);
    }

    public static function emitEmptyStdClass(Context $context): Variable
    {
        CastArrayShared::ensureInsertBlock($context, 'cast_object_empty_stdclass');
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        $objVal = $object->allocate($classId);
        $object->markObjectConstructed($objVal);

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objVal);
    }

    /** Zend convert_to_object on scalars / resources — stdClass with public scalar (#30098, #30793). */
    public static function emitScalarStdClass(Context $context, Variable $src): Variable
    {
        CastArrayShared::ensureInsertBlock($context, 'cast_object_scalar_stdclass');
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        if (!$object->hasProperty($classId, 'scalar')) {
            $object->defineProperty($classId, 'scalar', Variable::TYPE_VALUE);
        }
        $objVal = $object->allocate($classId);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'cast_object_scalar_after_alloc');
        $object->markObjectConstructed($objVal);
        $slot = $object->propertySlotFor($objVal, 'stdClass', 'scalar');
        if (Variable::TYPE_VALUE === $src->type) {
            // Point the property slot at the operand value box (cast consumes it) — same
            // pattern as hashtable→object (#26818 / #30793).
            $valuePtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($context, $src);
            $voidPtr = $context->getTypeFromString('void*');
            $context->builder->store(
                $context->builder->pointerCast($valuePtr, $voidPtr),
                $slot
            );
        } else {
            $object->propertyStore($slot, $src, Variable::TYPE_VALUE);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'cast_object_scalar_done');

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objVal);
    }

    /**
     * @return list<string>
     */
    private static function literalArrayKeysFromBlock(Block $block, Operand $srcOp): array
    {
        $keys = self::literalArrayKeysFromOperand($srcOp);
        if ([] !== $keys) {
            return array_values(array_unique($keys));
        }
        $arraySlot = self::operandSlot($block, $srcOp);
        if (null === $arraySlot) {
            return [];
        }
        $nextList = 0;
        foreach ($block->opCodes as $blockOp) {
            if (OpCode::TYPE_INIT_ARRAY === $blockOp->type && $blockOp->arg1 === $arraySlot) {
                if (null !== $blockOp->arg3) {
                    $key = self::literalKeyFromSlot($block, $blockOp->arg3);
                    if (null !== $key) {
                        $keys[] = $key;
                    }
                } elseif (null !== $blockOp->arg2) {
                    $keys[] = (string) $nextList;
                    ++$nextList;
                }
                continue;
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $blockOp->type && $blockOp->arg1 === $arraySlot) {
                if (null !== $blockOp->arg3) {
                    $key = self::literalKeyFromSlot($block, $blockOp->arg3);
                    if (null !== $key) {
                        $keys[] = $key;
                    }
                } else {
                    $keys[] = (string) $nextList;
                    ++$nextList;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private static function operandSlot(Block $block, Operand $target): ?int
    {
        // Block::$scope is private — use the public accessor (#30793 AOT fatal).
        return $block->slotForOperand($target);
    }

    private static function literalKeyFromSlot(Block $block, int $keySlot): ?string
    {
        if (!isset($block->constants[$keySlot])) {
            return null;
        }
        $constant = $block->constants[$keySlot];
        if (VmVariable::TYPE_STRING === $constant->type) {
            return $constant->toString();
        }
        if (VmVariable::TYPE_INTEGER === $constant->type) {
            return (string) $constant->toInt();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function literalArrayKeysFromOperand(Operand $operand): array
    {
        $keys = [];
        $seen = new \SplObjectStorage();
        $stack = [$operand];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if (!$current instanceof Operand || $seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            foreach ($current->ops as $exprOp) {
                if ($exprOp instanceof Op\Expr\Array_) {
                    foreach ($exprOp->keys as $i => $key) {
                        if ($key instanceof Literal && Type::TYPE_STRING === $key->type->type) {
                            $keys[] = (string) $key->value;
                        } elseif ($key instanceof Literal && Type::TYPE_LONG === $key->type->type) {
                            $keys[] = (string) $key->value;
                        } elseif ($key instanceof Operand\NullOperand) {
                            $keys[] = (string) $i;
                        }
                    }
                } elseif ($exprOp instanceof Op\Expr\Cast\Object_
                    || $exprOp instanceof Op\Expr\Cast\Array_
                    || $exprOp instanceof Op\Expr\Cast\Bool_
                    || $exprOp instanceof Op\Expr\Cast\Int_
                    || $exprOp instanceof Op\Expr\Cast\Double
                    || $exprOp instanceof Op\Expr\Cast\String_
                    || $exprOp instanceof Op\Expr\Cast\Unset_
                    || $exprOp instanceof Op\Expr\Cast\Void_) {
                    if ($exprOp->expr instanceof Operand) {
                        $stack[] = $exprOp->expr;
                    }
                } elseif ($exprOp instanceof Op\Expr\Assign && $exprOp->expr instanceof Operand) {
                    $stack[] = $exprOp->expr;
                }
            }
        }

        return $keys;
    }
}
