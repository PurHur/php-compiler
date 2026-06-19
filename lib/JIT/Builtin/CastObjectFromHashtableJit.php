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
use PHPLLVM\BasicBlock;
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
        $savedInsert = self::captureInsertBlock($context);
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
        $objVal = $object->allocate($classId);
        self::restoreInsertBlock($context, $savedInsert);
        $object->markObjectConstructed($objVal);
        $ht = $context->helper->loadValue($src);
        $className = 'stdClass';
        foreach ($object->instancePropertySets($classId) as $propset) {
            $propName = $propset[1];
            $keyStr = $context->builder->load($context->constantStringFromString($propName));
            $valEntry = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringKeyValue'),
                $ht,
                $keyStr
            );
            $slot = $object->propertySlotFor($objVal, $className, $propName);
            $context->builder->call(
                $context->lookupFunction('__object__load_value_slot'),
                $slot,
                $valEntry
            );
        }
        self::restoreInsertBlock($context, $savedInsert);

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

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
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
        foreach ($block->scope as $operand => $slot) {
            if ($operand === $target) {
                return $slot;
            }
        }

        return null;
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
