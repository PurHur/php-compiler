<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Emit readonly($obj) write guards before JIT property stores (#6485 / #36386).
 *
 * php-src: Zend/zend_object_handlers.c zend_std_write_property — Error when
 * ZEND_ACC_READONLY_OBJ is set.
 *
 * Hot path: load {@see __object__.dynamic_readonly}, branch to allow when clear.
 * Cold path: raise once with the object's class name. The class_id → name map
 * lives in a single module helper so each store site stays O(1) IR (#36386:
 * Node::bump was emitting a 70-arm switch at every ++$this->value).
 */
final class DynamicObjectReadonlyGuard
{
    private const FN_REJECT = 'phpc_dyn_readonly_reject';

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
        // NestedJIT / ctor / method entry can clear the insert block. Resume on
        // the function's last open BB so the dynamic_readonly GEP stays reachable
        // (ZEND_ASSIGN_OBJ, #32363 / #32367).
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
        $rejectBlock = $fn->appendBasicBlock('dyn_readonly_reject');
        $exitBlock = $fn->appendBasicBlock('dyn_readonly_exit');
        $context->builder->branchIf($isReadonly, $rejectBlock, $allowBlock);

        $context->builder->positionAtEnd($rejectBlock);
        $knownClass = $lvalue->objectPropertyClassName;
        if (is_string($knownClass) && '' !== $knownClass) {
            $message = 'unset' === $violation
                ? \sprintf('Cannot unset readonly object of class %s', $knownClass)
                : \sprintf('Cannot modify readonly object of class %s', $knownClass);
            ErrorRaise::emitRaise($context, $message);
        } else {
            self::ensureRejectHelper($context);
            $context->builder->call(
                $context->lookupFunction(self::FN_REJECT),
                $obj,
                $context->getTypeFromString('int32')->constInt('unset' === $violation ? 1 : 0, false)
            );
        }
        $context->builder->branch($exitBlock);

        $context->builder->positionAtEnd($allowBlock);
        $context->builder->branch($exitBlock);
        $context->builder->positionAtEnd($exitBlock);
    }

    /**
     * One module-level cold helper: class_id → "Cannot modify/unset readonly object of class %s".
     */
    private static function ensureRejectHelper(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::FN_REJECT);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction(self::FN_REJECT, $existing);

            return;
        }

        ErrorRaise::ensureLinked($context);
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);

        $saved = self::captureInsert($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $i32 = $context->getTypeFromString('int32');
        $void = $context->context->voidType();
        $ft = $context->context->functionType($void, false, $objPtr, $i32);
        $fn = null !== $existing ? $existing : $context->module->addFunction(self::FN_REJECT, $ft);
        $entry = $fn->appendBasicBlock('dyn_ro_reject_entry');
        $b = $context->builder;
        $b->positionAtEnd($entry);

        $obj = $fn->getParam(0);
        $isUnset = $fn->getParam(1);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $b->load($b->structGep($obj, $objMap['class_id']));

        $unsetBlock = $fn->appendBasicBlock('dyn_ro_unset');
        $modifyBlock = $fn->appendBasicBlock('dyn_ro_modify');
        $done = $fn->appendBasicBlock('dyn_ro_done');
        $isUnsetBool = $b->icmp(Builder::INT_NE, $isUnset, $i32->constInt(0, false));
        $b->branchIf($isUnsetBool, $unsetBlock, $modifyBlock);

        self::emitClassIdMessageSwitch(
            $context,
            $fn,
            $unsetBlock,
            $done,
            $classId,
            $objectType,
            true
        );
        self::emitClassIdMessageSwitch(
            $context,
            $fn,
            $modifyBlock,
            $done,
            $classId,
            $objectType,
            false
        );

        $b->positionAtEnd($done);
        $b->returnVoid();
        $context->registerFunction(self::FN_REJECT, $fn);
        self::restoreInsert($context, $saved);
    }

    /**
     * @param \PHPLLVM\Value\Function_ $fn
     */
    private static function emitClassIdMessageSwitch(
        Context $context,
        $fn,
        $startBlock,
        $doneBlock,
        Value $classId,
        Object_ $objectType,
        bool $isUnset
    ): void {
        $b = $context->builder;
        $classIds = array_keys($objectType->registeredClassNamesById());
        if ([] === $classIds) {
            $b->positionAtEnd($startBlock);
            ErrorRaise::emitRaise(
                $context,
                $isUnset
                    ? 'Cannot unset readonly object of class stdClass'
                    : 'Cannot modify readonly object of class stdClass'
            );
            $b->branch($doneBlock);

            return;
        }

        $tryBlock = $startBlock;
        $prefix = $isUnset ? 'dyn_ro_u' : 'dyn_ro_m';
        foreach ($classIds as $i => $id) {
            $matchBlock = $fn->appendBasicBlock($prefix.'_match_'.$id);
            $nextTry = $i + 1 < \count($classIds)
                ? $fn->appendBasicBlock($prefix.'_try_'.($i + 1))
                : null;
            $b->positionAtEnd($tryBlock);
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $b->icmp(Builder::INT_EQ, $classId, $expected);
            if (null !== $nextTry) {
                $b->branchIf($isId, $matchBlock, $nextTry);
            } else {
                // Last id: treat mismatch as stdClass-shaped fallback (readonly() on anon).
                $fallback = $fn->appendBasicBlock($prefix.'_fallback');
                $b->branchIf($isId, $matchBlock, $fallback);
                $b->positionAtEnd($fallback);
                ErrorRaise::emitRaise(
                    $context,
                    $isUnset
                        ? 'Cannot unset readonly object of class stdClass'
                        : 'Cannot modify readonly object of class stdClass'
                );
                $b->branch($doneBlock);
            }

            $b->positionAtEnd($matchBlock);
            $className = $objectType->classNameForId($id);
            $message = $isUnset
                ? \sprintf('Cannot unset readonly object of class %s', $className)
                : \sprintf('Cannot modify readonly object of class %s', $className);
            ErrorRaise::emitRaise($context, $message);
            $b->branch($doneBlock);
            $tryBlock = $nextTry;
        }
    }

    /**
     * @return array{0: ?\PHPLLVM\BasicBlock, 1: mixed}
     */
    private static function captureInsert(Context $context): array
    {
        return [
            BasicBlockHelper::tryGetInsertBlock($context),
            $context->builder,
        ];
    }

    /**
     * @param array{0: ?\PHPLLVM\BasicBlock, 1: mixed} $saved
     */
    private static function restoreInsert(Context $context, array $saved): void
    {
        [$block] = $saved;
        if (null !== $block) {
            BasicBlockHelper::restoreInsertBlock($context, $block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
