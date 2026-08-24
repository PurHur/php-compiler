<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMText::splitText() (php-src xmlTextSplitText).
 *
 * createTextNode stand-ins are unregistered DOMElement objects, so NestedJIT
 * DomRegistry split would abort. Fold compile-time data + offset like
 * {@see JitDomCreateTextNode}.
 *
 * php-src: ext/dom/text.c PHP_METHOD(DOMText, splitText) (#32362, #34314)
 */
final class JitDomSplitText
{
    /** Tail node data after the last compile-time split. */
    public static ?string $lastResultData = null;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        self::$lastResultData = null;
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_split_text_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMText::splitText() expects a receiver and offset');
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMText::splitText(): Argument #1 ($offset) must be of type int, null given'
            );

            return self::boxNullResult($context);
        }

        $data = $args[0]->compileTimeDomTextData ?? JitDomCreateTextNode::$lastMaterializedData;
        $offset = self::compileTimeOffset($args[1]);
        if (null === $data || null === $offset) {
            if (JitDomInstanceMethodKernel::shouldUse($context)) {
                throw new \LogicException(
                    'DOMText::splitText() user-script AOT requires compile-time data and offset'
                );
            }

            return DomInstanceMethodRuntime::invoke($context, 1, 'splittext', $args[0], $args[1]);
        }

        if ($offset < 0) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitValueErrorAndAbort(
                $context,
                'DOMText::splitText(): Argument #1 ($offset) must be greater than or equal to 0'
            );

            return self::boxNullResult($context);
        }

        $len = \strlen($data);
        if ($offset > $len) {
            return self::boxFalseResult($context);
        }

        $prefix = substr($data, 0, $offset);
        $suffix = substr($data, $offset);
        $receiverObj = self::loadObjectArg($context, $args[0]);
        JitDomCreateTextNode::overwriteCharacterData($context, $receiverObj, $prefix);
        $args[0]->compileTimeDomTextData = $prefix;
        self::$lastResultData = $suffix;

        $tailObj = JitDomCreateTextNode::materialize($context, $suffix);
        self::linkTailAfterReceiverIfParented($context, $args[0], $receiverObj, $tailObj, $suffix);

        return $tailObj;
    }

    /**
     * Parented split must insert the tail after the receiver (php-src xmlTextSplitText).
     * Detached createTextNode()->splitText keeps parentNode null (#32362).
     */
    private static function linkTailAfterReceiverIfParented(
        Context $context,
        JITVariable $receiverVar,
        Value $receiverObj,
        Value $tailObj,
        string $suffix
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_split_text_link');
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);

        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }

        $slotPtr = $context->builder->load(
            $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_PARENT_NODE)
        );
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullSlotBlock = BasicBlockHelper::append($context, 'dom_split_text_parent_slot_null');
        $readSlotBlock = BasicBlockHelper::append($context, 'dom_split_text_parent_slot_read');
        $slotMerge = BasicBlockHelper::append($context, 'dom_split_text_parent_slot_merge');
        $context->builder->branchIf($isNullSlot, $nullSlotBlock, $readSlotBlock);

        $context->builder->positionAtEnd($nullSlotBlock);
        $context->builder->branch($slotMerge);

        $context->builder->positionAtEnd($readSlotBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $parentFromSlot = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($slotMerge);

        $context->builder->positionAtEnd($slotMerge);
        $parentObj = $context->builder->phi($objPtrTy);
        $parentObj->addIncoming($objPtrTy->constNull(), $nullSlotBlock);
        $parentObj->addIncoming($parentFromSlot, $readSlotBlock);

        $parentNull = $context->builder->icmp(Builder::INT_EQ, $parentObj, $objPtrTy->constNull());
        $bbSkip = BasicBlockHelper::append($context, 'dom_split_text_link_skip');
        $bbLink = BasicBlockHelper::append($context, 'dom_split_text_link_do');
        $bbDone = BasicBlockHelper::append($context, 'dom_split_text_link_done');
        $context->builder->branchIf($parentNull, $bbSkip, $bbLink);

        $context->builder->positionAtEnd($bbLink);
        $parentVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $parentObj
        );
        $tailVar = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $tailObj
        );
        $tailVar->compileTimeDomTextData = $suffix;
        JitDomChildNodeSiblingInsert::invokeAfter($context, $parentVar, $tailVar, $receiverVar);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function compileTimeOffset(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return $arg->compileTimeLong;
        }
        if (null !== $arg->compileTimeFloat) {
            return (int) $arg->compileTimeFloat;
        }
        if (null !== $arg->compileTimeString && is_numeric($arg->compileTimeString)) {
            return (int) $arg->compileTimeString;
        }

        return null;
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMText::splitText() receiver must be an object');
    }

    private static function boxFalseResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
