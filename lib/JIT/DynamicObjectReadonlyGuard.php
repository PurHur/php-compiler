<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Builder;

/**
 * Emit readonly(object) write guards before JIT property stores (#6485).
 */
final class DynamicObjectReadonlyGuard
{
    public static function emitBeforePropertyStore(
        Context $context,
        Variable $lvalue,
        ?Block $enclosingBlock,
        string $violation = 'modify'
    ): void {
        if (null === $lvalue->objectPropertySlot) {
            return;
        }
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        if (null === $lvalue->objectPropertyReceiver && null !== $lvalue->objectPropertySlot) {
            $lvalue->objectPropertyReceiver = $objectType->receiverForPropertySlot($lvalue->objectPropertySlot);
        }
        if (null === $lvalue->objectPropertyReceiver) {
            return;
        }

        ErrorRaise::ensureLinked($context);
        // NestedJIT / ctor entry can clear the insert block. Resume on the
        // function's last open BB so the dynamic_readonly GEP stays reachable
        // (ZEND_ASSIGN_OBJ, #32363).
        BasicBlockHelper::positionAfterPrematureVoidReturn($context, 'dyn_readonly_resume');
        $entry = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $entry) {
            return;
        }
        $obj = $lvalue->objectPropertyReceiver;
        $objMap = $context->structFieldMap['__object__'];
        $flag = $context->builder->load(
            $context->builder->structGep($obj, $objMap['dynamic_readonly'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isReadonly = $context->builder->icmp(
            Builder::INT_NE,
            $flag,
            $i8->constInt(0, false)
        );
        $fn = $entry->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $allowBlock = $fn->appendBasicBlock('dyn_readonly_allow');
        $checkBlock = $fn->appendBasicBlock('dyn_readonly_check');
        $exitBlock = $fn->appendBasicBlock('dyn_readonly_exit');
        $context->builder->branchIf($isReadonly, $checkBlock, $allowBlock);

        $context->builder->positionAtEnd($checkBlock);
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $classIds = array_keys($objectType->registeredClassNamesById());
        if ([] === $classIds) {
            ErrorRaise::emitRaise($context, 'Cannot modify readonly object of class stdClass');
            $context->builder->branch($exitBlock);
        } else {
            $tryBlock = $checkBlock;
            foreach ($classIds as $i => $id) {
                $matchBlock = $fn->appendBasicBlock('dyn_readonly_match_'.$id);
                $nextTry = $i + 1 < \count($classIds)
                    ? $fn->appendBasicBlock('dyn_readonly_try_'.($i + 1))
                    : $allowBlock;
                $context->builder->positionAtEnd($tryBlock);
                $expected = $context->constantFromInteger($id, 'int64');
                $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
                $context->builder->branchIf($isId, $matchBlock, $nextTry);

                $context->builder->positionAtEnd($matchBlock);
                $className = $objectType->classNameForId($id);
                $message = 'unset' === $violation
                    ? \sprintf('Cannot unset readonly object of class %s', $className)
                    : \sprintf('Cannot modify readonly object of class %s', $className);
                ErrorRaise::emitRaise($context, $message);
                $context->builder->branch($exitBlock);
                $tryBlock = $nextTry;
            }
        }

        $context->builder->positionAtEnd($allowBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }
}
