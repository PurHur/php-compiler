<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\DomParseSimpleXmlJitHelper;
use PHPCompiler\ext\dom\JitDomChildNodeSiblingInsert;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\ext\dom\JitDomGetNodePath;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\ext\dom\JitDomNodeChildProperty;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for DOM ChildNode::{after,before,replaceWith,remove} (#26752).
 *
 * User-script AOT updates the parent's {@see VmDom::PROP_USER_SCRIPT_INNER_XML}
 * (same saveXML path as ParentNode append, #26757 / #26765).
 */
final class DomNodeChildNodeMutationRuntime
{
    public static function invokeAfter(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeSiblingMutation($context, 'after', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeBefore(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeSiblingMutation($context, 'before', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeReplaceWith(Context $context, int $extraArgCount, Variable $receiver, Variable ...$extraArgs): Value
    {
        return self::invokeSiblingMutation($context, 'replacewith', $extraArgCount, $receiver, ...$extraArgs);
    }

    public static function invokeRemove(Context $context, Variable $receiver): Value
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnode_remove');
            $parent = self::loadParentObject($context, $receiver);
            // Only-child / full-replace: clear parent inner markup for saveXML (#26752).
            JitDomCreateElement::storeUserScriptInnerXml($context, $parent, '');
            self::syncChildNodesLengthSlot($context, $parent, 0);

            return self::nullValuePtr($context);
        }

        return DomInstanceMethodRuntime::invoke($context, 0, 'remove', $receiver);
    }

    private static function invokeSiblingMutation(
        Context $context,
        string $kind,
        int $extraArgCount,
        Variable $receiver,
        Variable ...$extraArgs
    ): Value {
        if ($extraArgCount !== \count($extraArgs)) {
            throw new \LogicException('DomNodeChildNodeMutationRuntime arity mismatch');
        }
        if ($extraArgCount < 1 || $extraArgCount > DomNodeLiveMutationRuntime::MAX_EXTRA_ARGS) {
            throw new \LogicException('DomNodeChildNodeMutationRuntime unsupported arity');
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_childnode_'.$kind);
            $parent = self::loadParentObject($context, $receiver);
            if ('before' === $kind || 'after' === $kind) {
                if (!self::trySyncSiblingInsertInnerXml($context, $kind, $receiver, $parent, $extraArgs)) {
                    $parentVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $parent);
                    $newChildVar = $extraArgs[0];
                    if ('before' === $kind) {
                        JitDomChildNodeSiblingInsert::invokeBefore(
                            $context,
                            $parentVar,
                            $newChildVar,
                            $receiver
                        );
                    } else {
                        JitDomChildNodeSiblingInsert::invokeAfter(
                            $context,
                            $parentVar,
                            $newChildVar,
                            $receiver
                        );
                    }
                }
            } else {
                // replaceWith: replace parent InnerXml with the new node markup (#26752).
                self::storeInnerXmlFromArgs($context, $parent, $extraArgs);
            }

            return self::nullValuePtr($context);
        }

        return DomInstanceMethodRuntime::invoke($context, $extraArgCount, $kind, $receiver, ...$extraArgs);
    }

    /** @param list<Variable> $extraArgs */
    private static function storeInnerXmlFromArgs(Context $context, Value $parent, array $extraArgs): void
    {
        $pieces = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING === $arg->type) {
                $lit = $arg->compileTimeString ?? null;
                if (null === $lit) {
                    return;
                }
                $pieces[] = $lit;
                continue;
            }
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return;
            }
            $tag = $arg->compileTimeDomTagName ?? null;
            if (null === $tag || '' === $tag) {
                return;
            }
            $pieces[] = '<'.$tag.'/>';
        }
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, implode('', $pieces));
    }

    /**
     * Splice ChildNode::before/after args into parent PROP_USER_SCRIPT_INNER_XML (#26752).
     *
     * @param list<Variable> $extraArgs
     */
    private static function trySyncSiblingInsertInnerXml(
        Context $context,
        string $kind,
        Variable $receiver,
        Value $parent,
        array $extraArgs
    ): bool {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return false;
        }
        $markup = self::compileTimeMarkupFromArgs($extraArgs);
        if (null === $markup || '' === $markup) {
            return false;
        }
        $parentInner = self::compileTimeParentInnerXml();
        if (null === $parentInner) {
            return false;
        }
        $index = self::resolveReceiverChunkIndex($receiver, $parentInner);
        if (null === $index) {
            return false;
        }
        $after = 'after' === $kind;
        $inner = DomParseSimpleXmlJitHelper::innerXmlInsertMarkupAt($parentInner, $index, $markup, $after);
        if (null === $inner) {
            return false;
        }
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $inner);

        return true;
    }

    private static function compileTimeParentInnerXml(): ?string
    {
        if (!JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return null;
        }
        if (null !== JitDomGetNodePath::$lastParentInner) {
            return JitDomGetNodePath::$lastParentInner;
        }
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === trim($xml)) {
            return null;
        }

        return DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml);
    }

    private static function resolveReceiverChunkIndex(Variable $receiver, string $parentInner): ?int
    {
        if (null !== $receiver->compileTimeDomChildIndex) {
            return $receiver->compileTimeDomChildIndex;
        }
        $tag = $receiver->compileTimeDomTagName ?? JitDomNodeChildProperty::$lastFetchedTagName;
        if (null !== JitDomNodeChildProperty::$lastFetchedChildIndex && null !== $tag) {
            return JitDomNodeChildProperty::$lastFetchedChildIndex;
        }
        if (null === $tag || '' === $tag) {
            return null;
        }
        $tagLc = strtolower($tag);
        foreach (DomParseSimpleXmlJitHelper::directChildMarkupChunks($parentInner) as $i => $chunk) {
            $parsed = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($chunk);
            if (null !== $parsed && strtolower($parsed['tag']) === $tagLc) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<Variable> $extraArgs
     */
    private static function compileTimeMarkupFromArgs(array $extraArgs): ?string
    {
        $pieces = [];
        foreach ($extraArgs as $arg) {
            if (Variable::TYPE_STRING === $arg->type) {
                $lit = $arg->compileTimeString ?? null;
                if (null === $lit) {
                    return null;
                }
                $pieces[] = $lit;
                continue;
            }
            if (!\in_array($arg->type, [Variable::TYPE_OBJECT, Variable::TYPE_VALUE], true)) {
                return null;
            }
            $tag = $arg->compileTimeDomTagName ?? null;
            if (null === $tag || '' === $tag) {
                return null;
            }
            $pieces[] = '<'.$tag.'/>';
        }

        return implode('', $pieces);
    }

    private static function loadParentObject(Context $context, Variable $receiver): Value
    {
        $receiverObj = self::receiverObject($context, $receiver);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, Variable::TYPE_VALUE);
        }
        $slot = $objectType->propertySlotFor($receiverObj, 'DOMElement', VmDom::PROP_PARENT_NODE);
        $slotPtr = $context->builder->load($slot);
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNullSlot = $context->builder->icmp(Builder::INT_EQ, $slotPtr, $voidPtr->constNull());
        $nullBlock = BasicBlockHelper::append($context, 'dom_cn_parent_null');
        $readBlock = BasicBlockHelper::append($context, 'dom_cn_parent_read');
        $merge = BasicBlockHelper::append($context, 'dom_cn_parent_merge');
        $context->builder->branchIf($isNullSlot, $nullBlock, $readBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($readBlock);
        $valuePtr = $context->builder->pointerCast(
            $slotPtr,
            $context->getTypeFromString('__value__*')
        );
        $parentObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $nullBlock);
        $phi->addIncoming($parentObj, $readBlock);

        return $phi;
    }

    private static function syncChildNodesLengthSlot(Context $context, Value $parent, int $length): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, Variable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', Variable::TYPE_NATIVE_LONG);
        }
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthVar,
            Variable::TYPE_NATIVE_LONG
        );
        $listJit = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            Variable::TYPE_VALUE
        );
    }

    private static function receiverObject(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('DOM ChildNode receiver must be an object');
    }

    private static function nullValuePtr(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
